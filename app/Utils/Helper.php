<?php

namespace App\Utils;

use App\Services\Plugin\HookManager;
use Illuminate\Support\Arr;

class Helper
{
    public static function uuidToBase64($uuid, $length)
    {
        return base64_encode(substr($uuid, 0, $length));
    }

    /**
     * 派生 SS2022 server_key。
     *
     * 历史实现（$secret 为 null 时回退）：base64_encode(substr(md5($timestamp), 0, $length))
     *   - 这是已知弱密钥（输入空间 = Unix 时间戳，md5 截 hex 砍熵）
     *   - 但是订阅链路已经下发给了所有付费客户端，强行换 key 会让现有 SS2022 客户端立即断连
     *   - 因此默认行为不变，保证升级前后**无可观察破坏**
     *
     * 加固路径（推荐）：给该节点设置 v2_server.ss_secret = random_bytes(32)。
     *   Server::serverKey / generateServerPassword / ServerService::buildNodeConfig 都会
     *   优先用 HMAC-SHA256 派生 server_key，订阅下次拉取自动同步新 key。
     */
    public static function getServerKey($timestamp, $length, ?string $secret = null)
    {
        if ($secret !== null && $secret !== '') {
            // 输入混入 length 防止同节点不同密文长度互相还原内部状态
            $material = hash_hmac('sha256', "ss2022:{$length}:{$timestamp}", $secret, true);
            return base64_encode(substr($material, 0, $length));
        }
        return base64_encode(substr(md5((string) $timestamp), 0, $length));
    }

    public static function guid($format = false)
    {
        // 历史实现：openssl_random_pseudo_bytes(16) + md5 + time()。openssl_random_pseudo_bytes 返回值不保证
        // 加密强度（PHP 7.1+ 已经内部用 CSPRNG，但仍然没有 random_bytes 明确）；外层再 md5 等于把 128-bit 熵
        // 通过 md5 收口到 128-bit，本身没有降熵但隐藏了真实强度，且 md5() 的结果接在 time() 后再 md5 一次反而
        // 引入"时间戳猜测"路径（同 SS2022 server_key 的弱密钥模式）。
        //
        // 这里改成直接走 random_bytes(16)（CSPRNG）→ hex/uuid。输出长度与历史完全一致（32 / 36 字符），
        // 对前端透明；只是真正成为不可预测的 128-bit 随机串。
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        if ($format) {
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        // 32 字符 hex 字符串，与历史 md5() 输出格式（也是 32 hex）完全兼容
        return bin2hex($data);
    }

    public static function generateOrderNo(): string
    {
        // 原 mt_rand 是 Mersenne-Twister，2^32 seed，可由 ~624 个连续输出还原内部状态。
        // 订单号被用在 Payment notify URL 等公开路径，必须用 CSPRNG。
        $randomChar = random_int(10000, 99999);
        return date('YmdHms') . substr(microtime(), 2, 6) . $randomChar;
    }

    public static function exchange($from, $to)
    {
        // 历史用裸 file_get_contents：默认超时是 default_socket_timeout（60s+），
        // 上游 API 抽风时整个请求线程挂死；返回 null/解析失败也会让调用方拿 null 当成功。
        // 这里改用带超时的流上下文，并在异常路径返回 null（保持调用方"拿不到汇率就跳过"的兼容语义）。
        $opts = stream_context_create([
            'http' => [
                'timeout' => 3, // 短超时；汇率不是关键路径
                'ignore_errors' => true,
                'header' => "User-Agent: xboard-exchange-fetch\r\n",
            ],
        ]);
        try {
            $raw = @file_get_contents(
                'https://api.exchangerate.host/latest?symbols=' . urlencode($to) . '&base=' . urlencode($from),
                false,
                $opts
            );
            if ($raw === false) {
                return null;
            }
            $result = json_decode($raw, true);
            return $result['rates'][$to] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function randomChar($len, $special = false)
    {
        $chars = array(
            "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k",
            "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v",
            "w", "x", "y", "z", "A", "B", "C", "D", "E", "F", "G",
            "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q", "R",
            "S", "T", "U", "V", "W", "X", "Y", "Z", "0", "1", "2",
            "3", "4", "5", "6", "7", "8", "9"
        );

        if ($special) {
            $chars = array_merge($chars, array(
                "!", "@", "#", "$", "?", "|", "{", "/", ":", ";",
                "%", "^", "&", "*", "(", ")", "-", "_", "[", "]",
                "}", "<", ">", "~", "+", "=", ",", "."
            ));
        }

        $charsLen = count($chars) - 1;
        shuffle($chars);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            // CSPRNG：兑换码 / 优惠券 / 邀请码这一类持有即用的"凭据型字符串"必须走 random_int
            $str .= $chars[random_int(0, $charsLen)];
        }
        return $str;
    }

    public static function wrapIPv6($addr) {
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return "[$addr]";
        } else {
            return $addr;
        }
    }

    public static function multiPasswordVerify($algo, $salt, $password, $hash)
    {
        // 所有 legacy hash 比对统一走 hash_equals，避免 === 的字符串短路造成可观测的时序差异。
        // legacy 哈希仍保留是为了一次性登录验证后再升级到 bcrypt（见 AuthService），
        // 这里不能直接拒收 md5/sha256 否则历史用户全部锁死。
        $hash = (string) $hash;
        switch ($algo) {
            case 'md5':         return hash_equals($hash, md5($password));
            case 'sha256':      return hash_equals($hash, hash('sha256', $password));
            case 'md5salt':     return hash_equals($hash, md5($password . $salt));
            case 'sha256salt':  return hash_equals($hash, hash('sha256', $password . $salt));
            default:            return password_verify($password, $hash);
        }
    }

    public static function emailSuffixVerify($email, $suffixs)
    {
        $suffix = preg_split('/@/', $email)[1];
        if (!$suffix) return false;
        if (!is_array($suffixs)) {
            $suffixs = preg_split('/,/', $suffixs);
        }
        if (!in_array($suffix, $suffixs)) return false;
        return true;
    }

    public static function trafficConvert(float $byte)
    {
        $kb = 1024;
        $mb = 1048576;
        $gb = 1073741824;
        if ($byte > $gb) {
            return round($byte / $gb, 2) . ' GB';
        } else if ($byte > $mb) {
            return round($byte / $mb, 2) . ' MB';
        } else if ($byte > $kb) {
            return round($byte / $kb, 2) . ' KB';
        } else if ($byte < 0) {
            return 0;
        } else {
            return round($byte, 2) . ' B';
        }
    }

    public static function getSubscribeUrl(string $token, $subscribeUrl = null)
    {
        $path = route('client.subscribe', ['token' => $token], false);
        
        if ($subscribeUrl) {
            $finalUrl = rtrim($subscribeUrl, '/') . $path;
            return HookManager::filter('subscribe.url', $finalUrl);
        }
        
        $urlString = (string)admin_setting('subscribe_url', '');
        $subscribeUrlList = $urlString ? explode(',', $urlString) : [];
        
        if (empty($subscribeUrlList)) {
            return HookManager::filter('subscribe.url', url($path));
        }
        
        $selectedUrl = self::replaceByPattern(Arr::random($subscribeUrlList));
        $finalUrl = rtrim($selectedUrl, '/') . $path;
        
        return HookManager::filter('subscribe.url', $finalUrl);
    }

    public static function randomPort($range): int {
        $portRange = explode('-', (string) $range, 2);
        $min = (int) ($portRange[0] ?? 0);
        $max = (int) ($portRange[1] ?? $portRange[0] ?? 0);
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return random_int($min, $max);
    }

    public static function base64EncodeUrlSafe($data)
    {
        $encoded = base64_encode($data);
        return str_replace(['+', '/', '='], ['-', '_', ''], $encoded);
    }

    /**
     * 根据规则替换域名中对应的字符串
     *
     * @param string $input 用户输入的字符串
     * @return string 替换后的字符串
     */
    public static function replaceByPattern($input)
    {
        $patterns = [
            '/\[(\d+)-(\d+)\]/' => function ($matches) {
                $min = intval($matches[1]);
                $max = intval($matches[2]);
                if ($min > $max) {
                    list($min, $max) = [$max, $min];
                }
                $randomNumber = rand($min, $max);
                return $randomNumber;
            },
            '/\[uuid\]/' => function () {
                return  self::guid(true);
            }
        ];
        foreach ($patterns as $pattern => $callback) {
            $input = preg_replace_callback($pattern, $callback, $input);
        }
        return $input;
    }

    public static function getIpByDomainName($domain) {
        return gethostbynamel($domain) ?: [];
    }
    
    public static function getTlsFingerprint($utls = null)
    {

        if (is_array($utls) || is_object($utls)) {
            if (!data_get($utls, 'enabled')) {
                return null;
            }
            $fingerprint = data_get($utls, 'fingerprint', 'chrome');
            if ($fingerprint !== 'random') {
                return $fingerprint;
            }
        }

        $fingerprints = ['chrome', 'firefox', 'safari', 'ios', 'edge', 'qq'];
        return Arr::random($fingerprints);
    }

    public static function encodeURIComponent($str) {
        $revert = array('%21'=>'!', '%2A'=>'*', '%27'=>"'", '%28'=>'(', '%29'=>')');
        return strtr(rawurlencode($str), $revert);
    }

    public static function getEmailSuffix(): array|bool
    {
        $suffix = admin_setting('email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
    
    /**
     * convert the transfer_enable to GB
     * @param float $transfer_enable
     * @return float
     */
    public static function transferToGB(float $transfer_enable): float
    {
        return $transfer_enable / 1073741824;
    }

    /**
     * Normalize and validate ECH settings array.
     * Returns null if ECH is disabled or settings are missing/invalid.
     */
    public static function normalizeEchSettings($ech = null): ?array
    {
        if (!is_array($ech) && !is_object($ech)) {
            if ($ech === 'cloudflare') {
                return [
                    'enabled' => true,
                    'type' => 'cloudflare',
                    'config' => 'cloudflare-ech.com+https://doh.pub/dns-query',
                    'query_server_name' => 'cloudflare-ech.com',
                ];
            }
            return null;
        }

        if (!data_get($ech, 'enabled')) {
            return null;
        }

        if (data_get($ech, 'type') === 'cloudflare') {
            return [
                'enabled' => true,
                'type' => 'cloudflare',
                'config' => 'cloudflare-ech.com+https://doh.pub/dns-query',
                'query_server_name' => 'cloudflare-ech.com',
            ];
        }

        return array_filter([
            'enabled'           => true,
            'config'            => self::trimToNull(data_get($ech, 'config')),
            'query_server_name' => self::trimToNull(data_get($ech, 'query_server_name')),
            'key'               => self::trimToNull(data_get($ech, 'key')),
            'key_path'          => self::trimToNull(data_get($ech, 'key_path')),
            'config_path'       => self::trimToNull(data_get($ech, 'config_path')),
        ], static fn($value) => $value !== null);
    }

    /**
     * Convert an ECH config (PEM or raw base64) to a Mihomo-compatible base64 string.
     * Mihomo (Clash.Meta) expects raw base64-encoded ECHConfigList.
     */
    public static function toMihomoEchConfig(?string $config): ?string
    {
        $config = self::trimToNull($config);
        if (!$config) {
            return null;
        }

        // Mihomo expects raw base64-encoded ECHConfigList.
        // The 'cloudflare-ech.com+...' format is sing-box specific and causes
        // "illegal base64 data" errors in Mihomo. Return null to let Mihomo
        // auto-fetch ECH configs via DNS HTTPS records.
        if (str_starts_with($config, 'cloudflare-ech')) {
            return null;
        }

        if (str_starts_with($config, '-----BEGIN')) {
            if (preg_match('/-----BEGIN ECH CONFIGS-----\s*(.*?)\s*-----END ECH CONFIGS-----/s', $config, $matches)) {
                return preg_replace('/\s+/', '', $matches[1]);
            }
            return null;
        }

        return preg_replace('/\s+/', '', $config);
    }

    /**
     * Convert an ECH config for URI-based clients such as Shadowrocket/V2RayN.
     * These clients accept the Cloudflare resolver expression directly, unlike Mihomo.
     */
    public static function toUriEchConfig(?string $config): ?string
    {
        $config = self::trimToNull($config);
        if (!$config) {
            return null;
        }

        if (str_starts_with($config, 'cloudflare-ech')) {
            return $config;
        }

        if (str_starts_with($config, '-----BEGIN')) {
            if (preg_match('/-----BEGIN ECH CONFIGS-----\s*(.*?)\s*-----END ECH CONFIGS-----/s', $config, $matches)) {
                return preg_replace('/\s+/', '', $matches[1]);
            }
            return null;
        }

        return preg_replace('/\s+/', '', $config);
    }

    /**
     * Trim a value to a non-empty string, returning null if blank/non-string.
     */
    public static function trimToNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair for sing-box / Xray.
     * Produces ech_key (MarshalECHKeys format, for server inbound)
     * and ech_config (ECHConfigList, for client outbound).
     *
     * @param string $outerSni The cover/front domain for the outer ClientHello SNI.
     */
    public static function generateEchKeyPair($outerSni)
    {
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // ECHConfig contents per draft-ietf-tls-esni
        $configData = pack('C', $configId);              // config_id
        $configData .= pack('n', 0x0020);                // kem_id: DHKEM(X25519, HKDF-SHA256)
        $configData .= pack('n', 32) . $publicKey;       // public_key with length prefix
        // cipher suites: {HKDF-SHA256, AES-128-GCM}, {HKDF-SHA256, AES-256-GCM}, {HKDF-SHA256, ChaCha20-Poly1305}
        $suites = pack('nnnnnn', 0x0001, 0x0001, 0x0001, 0x0002, 0x0001, 0x0003);
        $configData .= pack('n', strlen($suites)) . $suites;
        $configData .= pack('C', 0);                     // maximum_name_length
        $configData .= pack('C', strlen($outerSni)) . $outerSni; // public_name
        $configData .= pack('n', 0);                     // extensions (empty)

        // ECHConfig = version(0xfe0d) + length + data
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($configData)) . $configData;

        // ECHConfigList for client
        $echConfigList = $echConfig;

        // MarshalECHKeys for server
        $echKeys = pack('n', strlen($echConfig)) . $echConfig;
        $echKeys .= pack('n', 1);                        // num_keys = 1
        $echKeys .= pack('C', $configId);                // config_id
        $echKeys .= pack('n', 32) . $privateKey;         // private key with length prefix

        return [
            'ech_key' => base64_encode($echKeys),
            'ech_config' => base64_encode($echConfigList),
        ];
    }
}
