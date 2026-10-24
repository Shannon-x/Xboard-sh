<?php

namespace Plugin\Epay;

use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use App\Contracts\PaymentInterface;
use App\Support\AdminAlert;
use App\Support\EpayOrderQuery;
use App\Support\EpaySignature;
use App\Support\EpayV2;
use App\Support\FeatureFlag;
use App\Support\PaymentGuard;
use App\Support\PaymentMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\PaymentAttempt\Support\Attempts;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    /** 统一下单拿到的支付链接缓存时长：同一个支付会话重复 checkout 不再重复建单（网关同号二次建单会报错） */
    private const PAY_URL_TTL = 600;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['EPay'] = [
                    'name' => $this->getConfig('display_name', '易支付'),
                    'icon' => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        // 后台把每个字段渲染成单行输入框、只显示 placeholder，所以用法写在 placeholder 里
        return [
            'url' => [
                'label' => '支付网关地址',
                'type' => 'string',
                'required' => true,
                'placeholder' => 'https://pay.example.com（含协议，结尾不要带接口文件名）',
            ],
            'pid' => [
                'label' => '商户ID',
                'type' => 'string',
                'required' => true,
                'placeholder' => '商户后台的商户ID',
            ],
            'api_version' => [
                'label' => '接口版本',
                'type' => 'string',
                'placeholder' => '留空 = V1（MD5 密钥）；填 v2 = V2（RSA，需填下面两个密钥）',
            ],
            'key' => [
                'label' => '通信密钥（V1）',
                'type' => 'string',
                'placeholder' => 'V1 的 MD5 商户密钥；用 V2 时可留空',
            ],
            'merchant_private_key' => [
                'label' => '商户私钥（V2）',
                'type' => 'text',
                'placeholder' => '商户后台 → 个人资料 → API信息 生成的商户私钥（整段粘贴，换行丢了也没关系）',
            ],
            'platform_public_key' => [
                'label' => '平台公钥（V2）',
                'type' => 'text',
                'placeholder' => '同一页面的平台公钥，用来验网关返回与回调的签名',
            ],
            'type' => [
                'label' => '支付类型',
                'type' => 'string',
                'placeholder' => 'alipay / wxpay / qqpay；留空进网关收银台',
            ],
            'v2_pay_mode' => [
                'label' => 'V2 下单方式',
                'type' => 'string',
                'placeholder' => '留空 = 服务器统一下单（下单失败能直接提示用户）；填 submit = 页面跳转',
            ],
            'verify_before_fulfil' => [
                'label' => '入账前向网关确认',
                'type' => 'string',
                'placeholder' => '留空 = 开启（推荐）；填 0 关闭。开启后每笔回调先查网关确认真有这笔、金额一致才开通',
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            // 文档要求「单位元，最大 2 位小数」：显式格式化，不靠浮点数转字符串
            'money' => sprintf('%.2f', $order['total_amount'] / 100),
            'name' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'out_trade_no' => $order['trade_no'],
        ];
        if (($paymentType = trim((string) $this->getConfig('type'))) !== '') {
            $params['type'] = $paymentType;
        }

        $config = (array) $this->getConfig();
        if (EpayV2::enabledFor($config)) {
            return ['type' => 1, 'data' => $this->payV2($config, $params)];
        }

        $params['pid'] = $this->getConfig('pid');
        $params['sign'] = EpaySignature::md5($params, (string) $this->getConfig('key'));
        $params['sign_type'] = 'MD5';

        return [
            'type' => 1,
            'data' => rtrim((string) $this->getConfig('url'), '/') . '/submit.php?' . http_build_query($params)
        ];
    }

    public function notify($params): array|bool
    {
        $v2 = EpayV2::enabledFor((array) $this->getConfig());
        $params = $this->authenticate((array) $params, $v2);
        if ($params === null) {
            PaymentMetrics::warn('webhook.sign_invalid', [
                'gateway' => 'EPay',
                'api_version' => $v2 ? 'v2' : 'v1',
            ]);
            return false;
        }

        $signType = strtoupper((string) ($params['sign_type'] ?? ''));
        unset($params['sign'], $params['sign_type']);

        // 两版文档都规定只有 TRADE_SUCCESS 表示付款成功：不随金额校验开关关闭
        if (($params['trade_status'] ?? null) !== 'TRADE_SUCCESS') {
            PaymentMetrics::warn('webhook.trade_status_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $params['out_trade_no'] ?? null,
                'trade_status' => $params['trade_status'] ?? null,
            ]);
            return false;
        }

        $mode = FeatureFlag::mode('payment_amount_check');
        if ($mode !== 'off') {
            $verdict = $this->verifyEpayPayload($params, $signType, $mode, $v2);
            if ($verdict === false) {
                return false;
            }
        }

        if (empty($params['out_trade_no']) || empty($params['trade_no'])) {
            PaymentMetrics::warn('webhook.identifiers_missing', ['gateway' => 'EPay']);
            return false;
        }

        $outTradeNo = (string) $params['out_trade_no'];
        $callbackNo = (string) $params['trade_no'];
        $paidMinor = PaymentGuard::decimalToMinor($params['money'] ?? null);

        if (!$this->confirmWithGateway($callbackNo, $outTradeNo, $paidMinor)) {
            return false;
        }

        // out_trade_no 可能是「支付会话号」而不是订单号（见 PaymentAttempt 插件）。
        // 易支付的 submit.php 是「创建预下单」接口，同一个 out_trade_no 只能创建一次，
        // 所以用订单号意味着一张单只能发起一次支付；换成每次发起都不同的会话号之后，
        // 用户可以退出来换别的支付方式、也可以换回同一个通道重新发起。
        $claim = $this->claimPaymentAttempt($outTradeNo, $callbackNo, $paidMinor);
        if ($claim !== null) {
            return $claim;
        }

        return [
            'trade_no' => $outTradeNo,
            'callback_no' => $callbackNo
        ];
    }

    /**
     * 向网关查询一笔交易（只读，付款事后核验用）。逻辑在 App\Support\EpayOrderQuery，
     * 这里按当前配置转调；返回结构见那边的说明。
     */
    public function queryOrder(string $gatewayTradeNo): array
    {
        return EpayOrderQuery::query((array) $this->getConfig(), $gatewayTradeNo);
    }

    /**
     * V2 下单。默认走统一下单（method=jump，服务器到服务器，拿回跳转链接）：通道关闭、金额超限之类的
     * 失败能当场提示用户换支付方式，浏览器里也看不到商户号与回调地址；没配支付类型（收银台模式）或
     * v2_pay_mode=submit 时退回页面跳转链接。
     */
    private function payV2(array $config, array $params): string
    {
        try {
            $client = EpayV2::fromConfig($config);
        } catch (\InvalidArgumentException $e) {
            Log::error('[epay-v2] 支付配置无效', ['payment_id' => $this->getConfig('id'), 'error' => $e->getMessage()]);
            throw new ApiException('支付通道配置有误，请更换支付方式或联系客服');
        }

        $mode = strtolower(trim((string) $this->getConfig('v2_pay_mode', '')));
        if ($mode === 'submit' || !isset($params['type'])) {
            return $client->submitUrl($params);
        }

        $cacheKey = 'epay_v2_pay_url:' . hash('sha256', implode("\0", [
            (string) $this->getConfig('id'), $params['out_trade_no'], $params['money'], $params['type'],
        ]));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $client->create($params + ['method' => 'jump', 'clientip' => $this->clientIp()]);
        $payInfo = (string) ($response['data']['pay_info'] ?? '');
        if (!$response['ok'] || !preg_match('#^https?://#i', $payInfo)) {
            PaymentMetrics::warn('pay.create_failed', [
                'gateway' => 'EPay',
                'out_trade_no' => $params['out_trade_no'],
                'code' => $response['code'],
                'msg' => $response['error'] ?? $response['msg'],
                'pay_type' => $response['data']['pay_type'] ?? null,
            ]);
            throw new ApiException('支付通道暂时不可用，请稍后重试或更换支付方式');
        }

        Cache::put($cacheKey, $payInfo, self::PAY_URL_TTL);
        return $payInfo;
    }

    private function clientIp(): string
    {
        try {
            $ip = request()?->ip();
        } catch (\Throwable) {
            $ip = null;
        }
        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }

    /**
     * 验签，返回可信的参数（null = 验签失败）。
     *
     * 先用控制器传进来的参数验；不过时再用原始查询串验一次：Kernel 的 TrimStrings 会去掉值首尾的
     * 空白，网关按原值签名，值里带空格的回调（比如商品名）会因此对不上。
     */
    private function authenticate(array $params, bool $v2): ?array
    {
        $candidates = [$params];
        $raw = $this->rawQueryParams();
        if ($raw !== null && $raw !== $params) {
            $candidates[] = $raw;
        }

        $client = null;
        if ($v2) {
            try {
                $client = EpayV2::fromConfig((array) $this->getConfig());
            } catch (\InvalidArgumentException $e) {
                PaymentMetrics::warn('webhook.config_invalid', ['gateway' => 'EPay', 'error' => $e->getMessage()]);
                return null;
            }
        }

        foreach ($candidates as $candidate) {
            $ok = $client !== null
                ? $client->verify($candidate)
                : EpaySignature::verifyMd5($candidate, (string) $this->getConfig('key'));
            if ($ok) {
                return $candidate;
            }
        }
        return null;
    }

    /** 当前 HTTP 请求的原始查询串（未经 TrimStrings / ConvertEmptyStringsToNull）；非 HTTP 上下文返回 null */
    private function rawQueryParams(): ?array
    {
        try {
            $query = request()?->server('QUERY_STRING');
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $raw);
        return is_array($raw) && $raw !== [] ? $raw : null;
    }

    /**
     * 入账前向网关确认（verify_before_fulfil，默认开）。网关明确说没有 / 未付 / 对不上就拒收，
     * 网关会按自己的策略重投；查询本身失败则放行，由 payment:verify 在 5 分钟后事后复查。
     */
    private function confirmWithGateway(string $callbackNo, string $outTradeNo, ?int $paidMinor): bool
    {
        $flag = strtolower(trim((string) $this->getConfig('verify_before_fulfil', '')));
        if (in_array($flag, ['0', 'off', 'false', 'no'], true)) {
            return true;
        }

        $check = EpayOrderQuery::confirm((array) $this->getConfig(), $callbackNo, $outTradeNo, $paidMinor);
        if ($check['verdict'] === EpayOrderQuery::CONFIRMED) {
            return true;
        }

        $context = ['gateway' => 'EPay', 'out_trade_no' => $outTradeNo, 'callback_no' => $callbackNo, 'reason' => $check['reason']];
        if ($check['verdict'] === EpayOrderQuery::UNAVAILABLE) {
            PaymentMetrics::warn('webhook.gateway_confirm_unavailable', $context);
            return true;
        }

        PaymentMetrics::warn('webhook.gateway_confirm_rejected', $context);
        // 网关会重投同一条回调，每单只告警一次
        if (Cache::add('epay_confirm_rejected:' . $outTradeNo, 1, 86400)) {
            $esc = [TelegramService::class, 'escapeHtml'];
            AdminAlert::send(
                "⚠️ <b>易支付回调被网关核验拒收</b>\n"
                . '单号：<code>' . $esc($outTradeNo) . "</code>\n"
                . '网关交易号：<code>' . $esc($callbackNo) . "</code>\n"
                . '原因：' . $esc($check['reason']) . "\n"
                . '签名有效但网关不认这笔，可能是密钥泄露后的伪造回调；若确属真实付款，请到网关核对后手动补单。',
                'html'
            );
        }
        return false;
    }

    /**
     * 交给支付会话层认领本次回调。
     *
     * 必须在**验签通过之后**调用：会话层不做验签，它假定调用方已经证明这条回调确实
     * 来自本条支付配置。
     *
     * @return array|bool|null 非 null 即为 notify() 的最终返回值；
     *                         null 表示「这不是会话号」，按普通订单号继续（启用会话
     *                         之前下的单走这条路）。
     */
    private function claimPaymentAttempt(string $outTradeNo, string $callbackNo, ?int $paidMinor): array|bool|null
    {
        // 插件未安装 / 目录被移除时整条逻辑不存在，退回原行为。
        if (!class_exists(Attempts::class)) {
            return null;
        }

        $claim = Attempts::claim($outTradeNo, (int) $this->getConfig('id'), $callbackNo, $paidMinor);

        switch ($claim['outcome'] ?? Attempts::OUTCOME_UNKNOWN) {
            case Attempts::OUTCOME_OK:
                return [
                    'trade_no' => (string) $claim['trade_no'],
                    'callback_no' => $callbackNo,
                    // 会话号原样带回去：核心 PaymentController 用不到会忽略它，而拥有
                    // 自有单据表的插件（余额充值）需要它来复核网关绑定与应付额。
                    'attempt_ref' => $outTradeNo,
                ];
            case Attempts::OUTCOME_REJECT:
                // 会话与回调网关对不上 —— 按验签失败拒收。
                return false;
            case Attempts::OUTCOME_REFUNDED:
            case Attempts::OUTCOME_MANUAL:
                // 已由会话层处置（退余额或告警转人工）。向网关 ACK 让它停止重投，但不开单。
                return ['acknowledge' => true, 'custom_result' => 'success'];
            default:
                return null;
        }
    }

    /**
     * 校验 EPay 回调的签名类型 / 商户号 / 金额是否与本地订单一致。
     *
     * 三级 flag：
     *   off     → 跳过校验（旧行为，默认）
     *   warn    → 仅记录指标与日志，不拒收（仅用于紧急兼容）
     *   enforce → 任意一项不一致即拒收
     */
    private function verifyEpayPayload(array $params, string $signType, string $mode, bool $v2): bool
    {
        $tradeNo = $params['out_trade_no'] ?? null;
        $money = $params['money'] ?? null;

        $expectedSignType = $v2 ? 'RSA' : 'MD5';
        if ($signType !== $expectedSignType) {
            PaymentMetrics::warn('webhook.sign_type_invalid', [
                'gateway' => 'EPay',
                'out_trade_no' => $tradeNo,
                'sign_type' => $signType,
            ]);
            if ($mode === 'enforce') {
                return false;
            }
        }

        if (!PaymentGuard::ensureMerchant(
            'EPay',
            'pid',
            isset($params['pid']) ? (string) $params['pid'] : null,
            (string) $this->getConfig('pid'),
            $mode
        )) {
            return false;
        }

        $actualMinor = PaymentGuard::decimalToMinor($money);
        if (!PaymentGuard::ensureAmount('EPay', $tradeNo, $actualMinor, $mode)) {
            return false;
        }

        return true;
    }
}
