<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * 易支付 V2 接口（彩虹易支付新版，SHA256WithRSA）。
 *
 * 和 V1 的根本区别：V1 下单、回调都用同一把共享的 MD5 商户密钥，密钥一旦泄露别人就能伪造
 * 「付款成功」回调；V2 由商户私钥给请求签名、平台私钥给返回与回调签名，
 * 我们只存「商户私钥 + 平台公钥」——配置即使泄露，也伪造不出平台签名的回调。
 *
 * 支付配置（v2_payment.config）里 api_version=v2 时启用，需要 merchant_private_key 与
 * platform_public_key（商户后台 → 个人资料 → API信息 生成）。
 *
 * 和 EpayOrderQuery 一样放在核心：生产上 plugins/ 是宿主机挂载，插件目录不一定随镜像更新。
 */
final class EpayV2
{
    /**
     * 接口返回的 timestamp 与本机相差超过这么多秒就不认（文档要求校验时间戳，防重放旧应答）。
     * 异步通知不查时间戳：网关重投的旧通知本来就是同一笔交易，开单幂等，拒掉只会造成掉单。
     */
    public const RESPONSE_MAX_SKEW = 600;
    private const TIMEOUT = 10;

    private function __construct(
        private readonly string $baseUrl,
        private readonly string $pid,
        private readonly OpenSSLAsymmetricKey $merchantPrivateKey,
        private readonly OpenSSLAsymmetricKey $platformPublicKey,
    ) {
    }

    public static function enabledFor(array $config): bool
    {
        return strtolower(trim((string) ($config['api_version'] ?? ''))) === 'v2';
    }

    /**
     * @throws InvalidArgumentException 配置缺项或密钥无法解析（异常信息不含密钥内容）
     */
    public static function fromConfig(array $config): self
    {
        $url = rtrim(trim((string) ($config['url'] ?? '')), '/');
        $pid = trim((string) ($config['pid'] ?? ''));
        if ($url === '' || $pid === '') {
            throw new InvalidArgumentException('易支付 V2 配置缺少网关地址或商户号');
        }

        $privateRaw = (string) ($config['merchant_private_key'] ?? '');
        // 商户后台给的多是 PKCS#8 裸 base64；也兼容粘贴了 PKCS#1（BEGIN RSA PRIVATE KEY）的
        $private = self::loadKey(fn () => openssl_pkey_get_private(self::pem($privateRaw, 'PRIVATE KEY')))
            ?? self::loadKey(fn () => openssl_pkey_get_private(self::pem($privateRaw, 'RSA PRIVATE KEY')));
        if ($private === null) {
            throw new InvalidArgumentException('易支付 V2 商户私钥无法解析');
        }
        $public = self::loadKey(fn () => openssl_pkey_get_public(self::pem((string) ($config['platform_public_key'] ?? ''), 'PUBLIC KEY')));
        if ($public === null) {
            throw new InvalidArgumentException('易支付 V2 平台公钥无法解析');
        }

        return new self($url, $pid, $private, $public);
    }

    /** 后台配置是单行输入框，粘贴进来的换行会丢：去掉 PEM 头尾与全部空白，按 64 列重新包装。 */
    public static function pem(string $key, string $label): string
    {
        $body = preg_replace('/-----(BEGIN|END)[A-Z ]*-----|\s+/', '', $key) ?? '';
        return "-----BEGIN {$label}-----\n" . chunk_split($body, 64, "\n") . "-----END {$label}-----\n";
    }

    public function sign(array $params): string
    {
        if (!openssl_sign(EpaySignature::content($params), $signature, $this->merchantPrivateKey, OPENSSL_ALGO_SHA256)) {
            self::clearOpensslErrors();
            throw new RuntimeException('易支付 V2 请求签名失败');
        }
        return base64_encode($signature);
    }

    /** 用平台公钥验接口返回 / 异步通知的签名。 */
    public function verify(array $params): bool
    {
        $sign = $params['sign'] ?? null;
        if (!is_string($sign) || $sign === '') {
            return false;
        }
        // 网关若没对 sign 做 URL 编码，查询串里的 + 会被解成空格
        $raw = base64_decode(str_replace(' ', '+', $sign), true);
        if ($raw === false || $raw === '') {
            return false;
        }
        $ok = openssl_verify(EpaySignature::content($params), $raw, $this->platformPublicKey, OPENSSL_ALGO_SHA256) === 1;
        self::clearOpensslErrors();
        return $ok;
    }

    /** 补上 pid / timestamp，并签名。 */
    public function signed(array $params): array
    {
        unset($params['sign'], $params['sign_type']);
        $params['pid'] = $this->pid;
        $params['timestamp'] = (string) time();
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'RSA';
        return $params;
    }

    /** 页面跳转支付链接（/api/pay/submit，GET 拼接）。不传 type 时进网关收银台。 */
    public function submitUrl(array $params): string
    {
        return $this->baseUrl . '/api/pay/submit?' . http_build_query($this->signed($params));
    }

    /**
     * POST 一个 V2 接口。
     *
     * ok=true 只在「code=0 且平台签名有效、时间戳在窗口内」时成立；
     * error 非 null 表示这次回答不可用（网络错误、非 JSON、验签或时间戳不过），与网关明确拒绝
     * （code≠0，msg 里是原因）区分开，调用方据此决定是重试还是放弃。
     *
     * $signedResponse=false：网关这个接口的应答不带签名（实测统一下单就是这样）。应答里只要带了
     * sign 仍然严格验；没带则放行，由调用方自行校验关键字段。
     *
     * @return array{ok: bool, code: mixed, msg: string, data: array, error: ?string}
     */
    public function call(string $path, array $params, bool $signedResponse = true): array
    {
        $result = ['ok' => false, 'code' => null, 'msg' => '', 'data' => [], 'error' => null];
        try {
            $response = Http::timeout(self::TIMEOUT)->asForm()->acceptJson()->post($this->baseUrl . $path, $this->signed($params));
        } catch (\Throwable $e) {
            $result['error'] = mb_substr(preg_replace('/\?\S*/', '?…', $e->getMessage()) ?? '', 0, 200);
            return $result;
        }
        if (!$response->successful()) {
            $result['error'] = 'HTTP ' . $response->status();
            return $result;
        }
        $json = $response->json();
        if (!is_array($json)) {
            $result['error'] = '网关返回的不是 JSON';
            return $result;
        }

        $result['code'] = $json['code'] ?? null;
        $result['msg'] = mb_substr(trim((string) ($json['msg'] ?? '')), 0, 200);
        $result['data'] = $json;
        if ((string) ($json['code'] ?? '') !== '0') {
            // 明确拒绝：失败的回答不一定带签名，不验
            return $result;
        }
        $hasSign = isset($json['sign']) && $json['sign'] !== '';
        if ($hasSign || $signedResponse) {
            if (!$this->verify($json)) {
                $result['error'] = '网关返回验签失败（平台公钥不对，或回答被篡改）';
                return $result;
            }
            $timestamp = $json['timestamp'] ?? null;
            if (!is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::RESPONSE_MAX_SKEW) {
                $result['error'] = '网关返回的时间戳超出允许范围';
                return $result;
            }
        }
        $result['ok'] = true;
        return $result;
    }

    /**
     * 统一下单（/api/pay/create）。$params 至少含 method / type / out_trade_no / notify_url / name / money / clientip。
     * 网关的下单应答不带签名（文档示例同样没有 sign，2026-10 实测）：调用方必须校验 pay_info 只指向网关自己的域名。
     */
    public function create(array $params): array
    {
        return $this->call('/api/pay/create', $params, false);
    }

    /** $url 是否就在网关自己的域名上（统一下单应答不签名，支付链接只认网关域名） */
    public function isGatewayUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host !== ''
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME))
            && $host === strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
    }

    /** 订单查询（/api/pay/query），平台订单号与商户订单号二选一 */
    public function query(string $tradeNo = '', string $outTradeNo = ''): array
    {
        return $this->call('/api/pay/query', array_filter(['trade_no' => $tradeNo, 'out_trade_no' => $outTradeNo], 'strlen'));
    }

    /** 查询商户信息（/api/merchant/info）：余额、今日 / 昨日订单数与收入 */
    public function merchantInfo(): array
    {
        return $this->call('/api/merchant/info', []);
    }

    /** 投诉列表（/api/complain/list） */
    public function complaints(array $filter = []): array
    {
        return $this->call('/api/complain/list', $filter);
    }

    /** @param callable(): (OpenSSLAsymmetricKey|false) $loader */
    private static function loadKey(callable $loader): ?OpenSSLAsymmetricKey
    {
        $key = @$loader();
        self::clearOpensslErrors();
        return $key instanceof OpenSSLAsymmetricKey ? $key : null;
    }

    /** OpenSSL 的错误队列是进程级的，Octane 常驻 worker 里不清会串到下一个请求的 openssl_error_string() */
    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
