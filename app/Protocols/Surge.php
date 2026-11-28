<?php

namespace App\Protocols;

use App\Support\CertPinHelper;
use App\Support\SurgePolicyGroups;
use App\Utils\Helper;
use Illuminate\Support\Facades\File;
use App\Support\AbstractProtocol;
use App\Models\Server;

class Surge extends AbstractProtocol
{
    public $flags = ['surge'];
    const CUSTOM_TEMPLATE_FILE = 'resources/rules/custom.surge.conf';
    const DEFAULT_TEMPLATE_FILE = 'resources/rules/default.surge.conf';

    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_TROJAN,
        Server::TYPE_HYSTERIA,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_HTTP,
        Server::TYPE_VLESS,
    ];
    protected $protocolRequirements = [
        'surge.hysteria.protocol_settings.version' => [2 => '2398'],
    ];

    /**
     * VLESS（含 Encryption）是否下发由 supportsSurge6Features() 按 UA 里的 Surge 内核版本决定，
     * 这里放开基类的加密节点黑名单，交给 handle() 统一判断。
     */
    protected $vlessEncryptionFormat = true;

    /**
     * Surge 6 新功能（VLESS、hysteria2 端口跳跃/Salamander/证书指纹）的最低版本。
     *
     * 官方手册 https://manual.nssurge.com/policies/vless.html 标注「VLESS iOS 6.0.0+ Mac 6.12.0+」，
     * 两个平台共用的协议内核是 SurgeCore 6.12.0（Mac 6.12.0 起新增 `vless` 类型）。
     * Surge 拉托管配置的 UA 形如 `Surge Mac/12900 SurgeCore/6012000`、`Surge iOS/4037 SurgeCore/6012000`：
     *   - 有 SurgeCore 段：按内核版本判断（6012000 = 6.12.0），iOS / Mac / tvOS 通用；
     *   - 没有 SurgeCore 段：只认 Mac 构建号 12900（Mac 6.12.0），iOS 构建号对不上版本，一律不给。
     * 旧版 Surge 读到不认识的代理类型会让整份托管配置报错，所以宁可不发也不能猜。
     */
    const SURGE6_MIN_CORE = 6012000;
    const SURGE6_MIN_MAC_BUILD = 12900;

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;

        $appName = admin_setting('app_name', 'XBoard');

        $proxies = '';
        $proxyGroup = '';
        $surge6 = $this->supportsSurge6Features();

        foreach ($servers as $item) {
            if (
                $item['type'] === Server::TYPE_SHADOWSOCKS
                && in_array(data_get($item, 'protocol_settings.cipher'), [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305',
                    '2022-blake3-aes-128-gcm',
                    '2022-blake3-aes-256-gcm'
                ])
            ) {
                $proxies .= self::buildShadowsocks($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === Server::TYPE_VMESS) {
                $proxies .= self::buildVmess($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === Server::TYPE_TROJAN) {
                $proxies .= self::buildTrojan($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === Server::TYPE_HYSTERIA) {
                $line = self::buildHysteria($item['password'], $item, $surge6);
                if ($line !== '') {
                    $proxies .= $line;
                    $proxyGroup .= $item['name'] . ', ';
                }
            }
            if ($item['type'] === Server::TYPE_VLESS && $surge6) {
                $line = self::buildVless($item['password'], $item);
                if ($line !== '') {
                    $proxies .= $line;
                    $proxyGroup .= $item['name'] . ', ';
                }
            }
            if ($item['type'] === Server::TYPE_ANYTLS) {
                $proxies .= self::buildAnyTLS($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === Server::TYPE_SOCKS) {
                $proxies .= self::buildSocks($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
            if ($item['type'] === Server::TYPE_HTTP) {
                $proxies .= self::buildHttp($item['password'], $item);
                $proxyGroup .= $item['name'] . ', ';
            }
        }


        $config = subscribe_template('surge');

        // Subscription link
        $subsDomain = request()->header('Host');
        $subsURL = Helper::getSubscribeUrl($user['token'], $subsDomain ? 'https://' . $subsDomain : null);

        $config = str_replace('$subs_link', $subsURL, $config);
        $config = str_replace('$subs_domain', $subsDomain, $config);
        $config = str_replace('$proxies', $proxies, $config);
        // 新模板写 $proxy_groups：由服务端按地区/线路倍率生成整段策略组（见 SurgePolicyGroups）。
        // 必须先于 $proxy_group 替换，否则 $proxy_group 会把 $proxy_groups 的前缀吃掉。
        // 没写 $proxy_groups 的旧模板行为不变。
        if (str_contains($config, '$proxy_groups')) {
            $names = array_values(array_filter(array_map('trim', explode(',', $proxyGroup))));
            $config = str_replace('$proxy_groups', (new SurgePolicyGroups($names))->render(), $config);
        }
        $config = str_replace('$proxy_group', rtrim($proxyGroup, ', '), $config);

        $upload = round($user['u'] / (1024 * 1024 * 1024), 2);
        $download = round($user['d'] / (1024 * 1024 * 1024), 2);
        $useTraffic = $upload + $download;
        $totalTraffic = round($user['transfer_enable'] / (1024 * 1024 * 1024), 2);
        $unusedTraffic = $totalTraffic - $useTraffic;
        $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
        $subscribeInfo = "title={$appName}订阅信息, content=上传流量：{$upload}GB\\n下载流量：{$download}GB\\n剩余流量：{$unusedTraffic}GB\\n套餐流量：{$totalTraffic}GB\\n到期时间：{$expireDate}";
        $config = str_replace('$subscribe_info', $subscribeInfo, $config);

        return response($config, 200)
            ->header('content-type', 'application/octet-stream')
            ->header('content-disposition', "attachment;filename*=UTF-8''" . rawurlencode($appName) . ".conf");
    }

    /**
     * 当前请求是否来自支持 Surge 6 新功能的 Surge（判断规则见 SURGE6_MIN_CORE）。
     * 读不出版本（比如 ?flag=surge 手动指定、或 UA 被改过）一律按旧版处理。
     */
    protected function supportsSurge6Features(): bool
    {
        $ua = strtolower((string) $this->userAgent);
        if (!str_contains($ua, 'surge')) {
            return false;
        }
        if (preg_match('/surgecore\/(\d+)/', $ua, $m)) {
            return (int) $m[1] >= self::SURGE6_MIN_CORE;
        }
        if (preg_match('/surge[\s_-]*mac(?:os)?\/(\d+)/', $ua, $m)) {
            return (int) $m[1] >= self::SURGE6_MIN_MAC_BUILD;
        }
        return false;
    }


    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "{$server['name']} = ss",
            "{$server['host']}",
            "{$server['port']}",
            "encrypt-method={$protocol_settings['cipher']}",
            "password={$password}",
            'tfo=true',
            'udp-relay=true'
        ];
        if (data_get($protocol_settings, 'plugin') && data_get($protocol_settings, 'plugin_opts')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = data_get($protocol_settings, 'plugin_opts', '');
            // 解析插件选项
            $parsedOpts = collect(explode(';', $pluginOpts))
                ->filter()
                ->mapWithKeys(function ($pair) {
                    if (!str_contains($pair, '=')) {
                        return [];
                    }
                    [$key, $value] = explode('=', $pair, 2);
                    return [trim($key) => trim($value)];
                })
                ->all();
            switch ($plugin) {
                case 'obfs':
                    $config[] = "obfs={$parsedOpts['obfs']}";
                    if (isset($parsedOpts['obfs-host'])) {
                        $config[] = "obfs-host={$parsedOpts['obfs-host']}";
                    }
                    if (isset($parsedOpts['path'])) {
                        $config[] = "obfs-uri={$parsedOpts['path']}";
                    }
                    break;
            }
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "{$server['name']} = vmess",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
            "vmess-aead=true",
            'tfo=true',
            'udp-relay=true'
        ];

        if (data_get($protocol_settings, 'tls')) {
            array_push($config, 'tls=true');
            if (data_get($protocol_settings, 'tls_settings')) {
                $tlsSettings = data_get($protocol_settings, 'tls_settings');
                if (data_get($tlsSettings, 'allow_insecure'))
                    array_push($config, 'skip-cert-verify=' . ($tlsSettings['allow_insecure'] ? 'true' : 'false'));
                if (data_get($tlsSettings, 'server_name'))
                    array_push($config, "sni={$tlsSettings['server_name']}");
            }
        }
        if (data_get($protocol_settings, 'network') === 'ws') {
            array_push($config, 'ws=true');
            if (data_get($protocol_settings, 'network_settings')) {
                $wsSettings = data_get($protocol_settings, 'network_settings');
                if (data_get($wsSettings, 'path'))
                    array_push($config, "ws-path={$wsSettings['path']}");
                if (data_get($wsSettings, 'headers.Host'))
                    array_push($config, "ws-headers=Host:{$wsSettings['headers']['Host']}");
            }
        }

        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $config = [
            "{$server['name']} = trojan",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            data_get($protocol_settings, 'server_name') ? "sni=" . data_get($protocol_settings, 'server_name') : "",
            'tfo=true',
            'udp-relay=true'
        ];
        if (!empty($protocol_settings['allow_insecure'])) {
            array_push($config, !!data_get($protocol_settings, 'allow_insecure') ? 'skip-cert-verify=true' : 'skip-cert-verify=false');
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    //参考文档: https://manual.nssurge.com/policy/proxy.html
    public static function buildAnyTLS($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $config = [
            "{$server['name']} = anytls",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            'tfo=true',
        ];
        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $config[] = "sni={$serverName}";
        }
        if (data_get($protocol_settings, 'tls.allow_insecure')) {
            $config[] = 'skip-cert-verify=true';
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    /**
     * 参考文档: https://manual.nssurge.com/policies/hysteria2.html
     *
     * @param bool $surge6 客户端是否 Surge 6（见 supportsSurge6Features），决定能否用下面三个较新的参数：
     *   - port-hopping：节点端口是范围（如 53000-54000）时下发，Surge 会忽略主端口在范围内轮换；
     *   - salamander-password：Salamander 混淆（手册标 Mac 6.4.3+，iOS 未标版本）；
     *   - server-cert-fingerprint-sha256：用证书指纹代替链校验（比 skip-cert-verify 安全）。
     *   旧版 Surge 不认识这些参数时会让配置报错，所以只给 Surge 6；旧版维持原来的写法。
     */
    public static function buildHysteria($password, $server, bool $surge6 = false)
    {
        $protocol_settings = $server['protocol_settings'];
        if ($protocol_settings['version'] != 2)
            return '';

        $obfsOn = (bool) data_get($protocol_settings, 'obfs.open');
        $obfsType = data_get($protocol_settings, 'obfs.type', 'salamander') ?: 'salamander';
        $obfsPassword = data_get($protocol_settings, 'obfs.password');
        if ($obfsOn) {
            // 混淆开着但写不出来 = 节点必然连不上，不如不发
            if (!$surge6 || $obfsType !== 'salamander' || blank($obfsPassword)) {
                return '';
            }
        }

        $config = [
            "{$server['name']} = hysteria2",
            "{$server['host']}",
            "{$server['port']}",
            "password={$password}",
            data_get($protocol_settings, 'tls.server_name') ? "sni={$protocol_settings['tls']['server_name']}" : "",
            // 'tfo=true', 
            'udp-relay=true'
        ];
        if ($surge6 && !empty($server['ports'])) {
            $config[] = 'port-hopping="' . str_replace(',', ';', (string) $server['ports']) . '"';
            if ($interval = (int) data_get($protocol_settings, 'hop_interval')) {
                $config[] = "port-hopping-interval={$interval}";
            }
        }
        if ($obfsOn) {
            $config[] = 'salamander-password="' . $obfsPassword . '"';
        }
        if (data_get($protocol_settings, 'bandwidth.up')) {
            $config[] = "upload-bandwidth={$protocol_settings['bandwidth']['up']}";
        }
        if (data_get($protocol_settings, 'bandwidth.down')) {
            $config[] = "download-bandwidth={$protocol_settings['bandwidth']['down']}";
        }
        // 有指纹（= 面板自签证书）时链校验一定失败：Surge 6 下发指纹，旧版只能跳过链校验，
        // 否则该节点在 Surge 上必然握手失败。
        $pin = CertPinHelper::normalizePin(data_get($protocol_settings, 'tls.pinned_peer_cert_sha256'));
        if (data_get($protocol_settings, 'tls.allow_insecure')) {
            $config[] = 'skip-cert-verify=true';
        } elseif ($pin !== null) {
            $config[] = $surge6 ? "server-cert-fingerprint-sha256={$pin}" : 'skip-cert-verify=true';
        }
        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    /**
     * 参考文档: https://manual.nssurge.com/policies/vless.html（VLESS iOS 6.0.0+ Mac 6.12.0+）
     *
     * 支持：RAW(tcp) / WebSocket / HTTPUpgrade / gRPC / XHTTP；无 TLS / TLS / REALITY；
     *       xtls-rprx-vision flow；VLESS Encryption（mlkem768x25519plus）。
     * 不支持、整条不下发：h2、mKCP、tcp+http 伪装、ws/httpupgrade+REALITY、
     *       gRPC 不带 TLS、flow 搭在 ws/gRPC/XHTTP 上（Surge 只在 RAW+TLS/REALITY 或 Encryption 上跑 Vision）。
     * 忽略、照常下发：ECH（Surge 只收内联 ECHConfigList，且要 6.13 测试版；
     *       面板的 cloudflare 自动获取写不出来，不带 ECH 连 Cloudflare 照样能通）、multiplex（Surge 没有）。
     * UDP 不需要参数，Surge 固定走 XUDP。
     *
     * @return string 一行 Surge 代理；不支持的组合返回空串
     */
    public static function buildVless($uuid, $server): string
    {
        $ps = data_get($server, 'protocol_settings', []);
        $tlsMode = (int) data_get($ps, 'tls', 0);
        $network = strtolower(trim((string) data_get($ps, 'network', 'tcp'))) ?: 'tcp';
        if ($network === 'raw') {
            $network = 'tcp';
        }
        $flow = (string) data_get($ps, 'flow', '');
        $flow = in_array($flow, ['', 'none'], true) ? '' : $flow;

        $encryption = '';
        if (data_get($ps, 'encryption') === 'mlkem768x25519plus') {
            $enc = data_get($ps, 'encryption_settings', []) ?: [];
            $encryption = 'mlkem768x25519plus.' . ($enc['mode'] ?? 'native') . '.' . ($enc['rtt'] ?? '1rtt');
            if (!empty($enc['client_padding'])) {
                $encryption .= '.' . $enc['client_padding'];
            }
            $encryption .= '.' . ($enc['password'] ?? '');
        } elseif (!in_array((string) data_get($ps, 'encryption'), ['', 'none'], true)) {
            return ''; // 不认识的加密方式
        }

        if (!in_array($network, ['tcp', 'ws', 'httpupgrade', 'grpc', 'xhttp'], true)) {
            return '';
        }
        if ($network === 'tcp' && data_get($ps, 'network_settings.header.type', 'none') === 'http') {
            return '';
        }
        if ($tlsMode === 2 && in_array($network, ['ws', 'httpupgrade'], true)) {
            return '';
        }
        if ($network === 'grpc' && $tlsMode === 0) {
            return '';
        }
        if ($flow !== '') {
            if (!in_array($flow, ['xtls-rprx-vision', 'xtls-rprx-vision-udp443'], true)) {
                return '';
            }
            $visionOk = $network === 'tcp' && ($tlsMode !== 0 || $encryption !== '');
            if (!$visionOk) {
                return '';
            }
        }
        if ($tlsMode === 2 && blank(data_get($ps, 'reality_settings.public_key'))) {
            return '';
        }

        $config = [
            "{$server['name']} = vless",
            "{$server['host']}",
            "{$server['port']}",
            "username={$uuid}",
        ];
        if ($flow !== '') {
            $config[] = "flow={$flow}";
        }
        if ($encryption !== '') {
            $config[] = "encryption={$encryption}";
        }

        switch ($network) {
            case 'ws':
            case 'httpupgrade':
                $config[] = 'ws=true';
                if ($network === 'httpupgrade') {
                    $config[] = 'ws-http-upgrade=true';
                }
                if ($path = data_get($ps, 'network_settings.path')) {
                    $config[] = "ws-path={$path}";
                }
                $host = data_get($ps, 'network_settings.headers.Host') ?: data_get($ps, 'network_settings.host');
                if (is_array($host)) {
                    $host = $host[0] ?? null;
                }
                if ($host) {
                    $config[] = "ws-headers=Host:{$host}";
                }
                break;
            case 'grpc':
                $config[] = 'grpc=true';
                if ($serviceName = data_get($ps, 'network_settings.serviceName')) {
                    $config[] = "grpc-service-name={$serviceName}";
                }
                break;
            case 'xhttp':
                $config[] = 'xhttp=true';
                if ($path = data_get($ps, 'network_settings.path')) {
                    $config[] = "xhttp-path={$path}";
                }
                if ($host = data_get($ps, 'network_settings.host')) {
                    $config[] = 'xhttp-host=' . (is_array($host) ? ($host[0] ?? '') : $host);
                }
                $mode = data_get($ps, 'network_settings.mode');
                if (in_array($mode, ['auto', 'packet-up', 'stream-up', 'stream-one'], true)) {
                    $config[] = "xhttp-mode={$mode}";
                }
                break;
        }

        if ($tlsMode === 2) {
            // REALITY：隐含 TLS，不能写 tls=true / skip-cert-verify / 指纹
            if ($sni = data_get($ps, 'reality_settings.server_name')) {
                $config[] = "sni={$sni}";
            }
            $config[] = 'reality-public-key=' . data_get($ps, 'reality_settings.public_key');
            if ($shortId = data_get($ps, 'reality_settings.short_id')) {
                $config[] = "reality-short-id={$shortId}";
            }
        } elseif ($tlsMode === 1) {
            $config[] = 'tls=true';
            if ($sni = data_get($ps, 'tls_settings.server_name')) {
                $config[] = "sni={$sni}";
            }
            if (data_get($ps, 'tls_settings.allow_insecure')) {
                $config[] = 'skip-cert-verify=true';
            } elseif ($pin = CertPinHelper::normalizePin(data_get($ps, 'tls_settings.pinned_peer_cert_sha256'))) {
                $config[] = "server-cert-fingerprint-sha256={$pin}";
            }
        }
        $config[] = 'tfo=true';

        return implode(',', array_filter($config, fn($v) => $v !== '' && $v !== null)) . "\r\n";
    }

    //参考文档: https://manual.nssurge.com/policy/proxy.html
    public static function buildSocks($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $type = data_get($protocol_settings, 'tls') ? 'socks5-tls' : 'socks5';
        $config = [
            "{$server['name']} = {$type}",
            "{$server['host']}",
            "{$server['port']}",
            "{$password}",
            "{$password}",
        ];

        if (data_get($protocol_settings, 'tls')) {
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $config[] = "sni={$serverName}";
            }
            if (data_get($protocol_settings, 'tls_settings.allow_insecure')) {
                $config[] = 'skip-cert-verify=true';
            }
        }
        $config[] = 'udp-relay=true';

        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }

    //参考文档: https://manual.nssurge.com/policy/proxy.html
    public static function buildHttp($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $type = data_get($protocol_settings, 'tls') ? 'https' : 'http';
        $config = [
            "{$server['name']} = {$type}",
            "{$server['host']}",
            "{$server['port']}",
            "{$password}",
            "{$password}",
        ];

        if (data_get($protocol_settings, 'tls')) {
            if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                $config[] = "sni={$serverName}";
            }
            if (data_get($protocol_settings, 'tls_settings.allow_insecure')) {
                $config[] = 'skip-cert-verify=true';
            }
        }

        $config = array_filter($config);
        $uri = implode(',', $config);
        $uri .= "\r\n";
        return $uri;
    }
}
