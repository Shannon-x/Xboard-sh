<?php

namespace App\Protocols;

use App\Utils\Helper;
use App\Support\AbstractProtocol;
use App\Support\CertPinHelper;
use App\Models\Server;

class QuantumultX extends AbstractProtocol
{
    public $flags = ['quantumult%20x', 'quantumult-x'];
    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
        Server::TYPE_HTTP,
        Server::TYPE_HYSTERIA,
    ];

    /**
     * 圈X 从 1.9.0（build 965）起才认 hysteria2= 节点。
     * UA 只带 1.9.0 不带 build 号，所以按 1.9.0 卡；读不到版本的（只带 flag 的请求）一律不发，
     * 免得旧版圈X 拿到不认识的行。
     */
    const HYSTERIA2_MIN_VERSION = '1.9.0';

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $uri = '';
        $supportsHysteria2 = $this->supportsHysteria2();
        foreach ($servers as $item) {
            $uri .= match ($item['type']) {
                Server::TYPE_SHADOWSOCKS => self::buildShadowsocks($item['password'], $item),
                Server::TYPE_VMESS => self::buildVmess($item['password'], $item),
                Server::TYPE_VLESS => self::buildVless($item['password'], $item),
                Server::TYPE_TROJAN => self::buildTrojan($item['password'], $item),
                Server::TYPE_ANYTLS => self::buildAnyTLS($item['password'], $item),
                Server::TYPE_SOCKS => self::buildSocks5($item['password'], $item),
                Server::TYPE_HTTP => self::buildHttp($item['password'], $item),
                Server::TYPE_HYSTERIA => $supportsHysteria2 ? self::buildHysteria($item['password'], $item) : '',
                default => ''
            };
        }
        return response(base64_encode($uri))
            ->header('content-type', 'text/plain')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
    }

    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $password = data_get($server, 'password', $password);
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "shadowsocks={$addr}:{$server['port']}",
            "method=" . data_get($protocol_settings, 'cipher'),
            "password={$password}",
        ];

        if (data_get($protocol_settings, 'plugin') && data_get($protocol_settings, 'plugin_opts')) {
            $plugin = data_get($protocol_settings, 'plugin');
            $pluginOpts = data_get($protocol_settings, 'plugin_opts', '');
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
            if ($plugin === 'obfs') {
                if (isset($parsedOpts['obfs'])) {
                    $config[] = "obfs={$parsedOpts['obfs']}";
                }
                if (isset($parsedOpts['obfs-host'])) {
                    $config[] = "obfs-host={$parsedOpts['obfs-host']}";
                }
                if (isset($parsedOpts['path'])) {
                    $config[] = "obfs-uri={$parsedOpts['path']}";
                }
            }
        }

        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "vmess={$addr}:{$server['port']}",
            "method=" . data_get($protocol_settings, 'cipher', 'auto'),
            "password={$uuid}",
        ];

        self::applyTransportSettings($config, $protocol_settings);
        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    public static function buildVless($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "vless={$addr}:{$server['port']}",
            'method=none',
            "password={$uuid}",
        ];

        self::applyTransportSettings($config, $protocol_settings);

        if ($flow = data_get($protocol_settings, 'flow')) {
            $config[] = "vless-flow={$flow}";
        }

        // 处理 VLESS encryption (e.g. mlkem768x25519plus)
        $encryption = data_get($protocol_settings, 'encryption');
        if ($encryption === 'mlkem768x25519plus') {
            $encSettings = data_get($protocol_settings, 'encryption_settings', []);
            $encStr = $encryption . '.' . ($encSettings['mode'] ?? 'native') . '.' . ($encSettings['rtt'] ?? '1rtt');
            if (!empty($encSettings['client_padding'])) {
                $encStr .= '.' . $encSettings['client_padding'];
            }
            $encStr .= '.' . ($encSettings['password'] ?? '');
            $config[] = "encryption={$encStr}";
        }
        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    private static function applyTransportSettings(&$config, $settings, bool $nativeTls = false, ?array $tlsData = null)
    {
        $tlsMode = (int) data_get($settings, 'tls', 0);
        $network = data_get($settings, 'network', 'tcp');
        $host = null;
        $isWs = $network === 'ws';

        switch ($network) {
            case 'ws':
                $config[] = $tlsMode ? 'obfs=wss' : 'obfs=ws';
                if ($path = data_get($settings, 'network_settings.path')) {
                    $config[] = "obfs-uri={$path}";
                }
                $host = data_get($settings, 'network_settings.headers.Host');
                break;
            case 'tcp':
                $headerType = data_get($settings, 'network_settings.header.type', 'tcp');
                if ($headerType === 'http') {
                    $config[] = 'obfs=http';
                    $paths = data_get($settings, 'network_settings.header.request.path', ['/']);
                    $config[] = 'obfs-uri=' . (is_array($paths) ? ($paths[0] ?? '/') : $paths);
                    $hostVal = data_get($settings, 'network_settings.header.request.headers.Host');
                    $host = is_array($hostVal) ? ($hostVal[0] ?? null) : $hostVal;
                } elseif ($tlsMode) {
                    $config[] = $nativeTls ? 'over-tls=true' : 'obfs=over-tls';
                }
                break;
        }

        switch ($tlsMode) {
            case 2: // Reality
                $host = $host ?? data_get($settings, 'reality_settings.server_name');
                if ($pubKey = data_get($settings, 'reality_settings.public_key')) {
                    $config[] = "reality-base64-pubkey={$pubKey}";
                }
                if ($shortId = data_get($settings, 'reality_settings.short_id')) {
                    $config[] = "reality-hex-shortid={$shortId}";
                }
                break;
            case 1: // TLS
                $resolved = $tlsData ?? (array) data_get($settings, 'tls_settings', []);
                $allowInsecure = (bool) ($resolved['allow_insecure'] ?? false);
                $config[] = 'tls-verification=' . ($allowInsecure ? 'false' : 'true');
                $host = $host ?? ($resolved['server_name'] ?? null);
                break;
        }

        if ($host) {
            $config[] = ($nativeTls && !$isWs) ? "tls-host={$host}" : "obfs-host={$host}";
        }
    }

    private static function applyCommonSettings(&$config, $server)
    {
        $config[] = 'fast-open=true';
        if ($server['type'] !== Server::TYPE_HTTP) {
            $config[] = 'udp-relay=true';
        }
        $config[] = "tag={$server['name']}";
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "trojan={$addr}:{$server['port']}",
            "password={$password}",
        ];

        $tlsData = [
            'allow_insecure' => data_get($protocol_settings, 'allow_insecure', false),
            'server_name' => data_get($protocol_settings, 'server_name'),
        ];
        self::applyTransportSettings($config, $protocol_settings, true, $tlsData);
        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    public static function buildAnyTLS($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "anytls={$addr}:{$server['port']}",
            "password={$password}",
            'udp-relay=true',
            "tag={$server['name']}",
            "over-tls=true",
        ];

        // allow_insecure=false => tls-verification=true；
        // allow_insecure=true 时不写，沿用 QX 默认 false
        $allowInsecure = (bool) data_get($protocol_settings, 'tls.allow_insecure', false);
        if (!$allowInsecure) {
            $config[] = 'tls-verification=true';
        }

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $config[] = "tls-host=$serverName";
        }

        return implode(',', array_filter($config)) . "\r\n";
    }

    public static function buildSocks5($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "socks5={$addr}:{$server['port']}",
            "username={$password}",
            "password={$password}",
        ];

        self::applyTransportSettings($config, $protocol_settings, true);
        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    public static function buildHttp($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "http={$addr}:{$server['port']}",
            "username={$password}",
            "password={$password}",
        ];

        self::applyTransportSettings($config, $protocol_settings, true);
        self::applyCommonSettings($config, $server);

        return implode(',', array_filter($config)) . "\r\n";
    }

    /**
     * 当前请求的圈X 能不能收 hysteria2 节点：必须读到版本号且 >= HYSTERIA2_MIN_VERSION
     */
    protected function supportsHysteria2(): bool
    {
        if (!in_array($this->clientName, $this->flags, true)) {
            return false;
        }
        return !blank($this->clientVersion)
            && version_compare($this->clientVersion, self::HYSTERIA2_MIN_VERSION, '>=');
    }

    /**
     * hysteria2=example.com:443, password=pwd, salamander-password=obfskey, tls-host=example.com,
     * tls-verification=true, download-bandwidth=100, port-hopping=20000-50000, port-hopping-interval=30,
     * udp-relay=true, tag=hy2-01
     *
     * 圈X 只支持 Hysteria 2，v1 节点不发。
     */
    public static function buildHysteria($password, $server)
    {
        $protocol_settings = data_get($server, 'protocol_settings', []);
        if ((int) data_get($protocol_settings, 'version', 2) !== 2) {
            return '';
        }

        // 混淆只认 salamander，开了别的类型的节点圈X 连不上，不发
        $salamander = null;
        if (data_get($protocol_settings, 'obfs.open')) {
            $obfsType = strtolower((string) data_get($protocol_settings, 'obfs.type', 'salamander'));
            $obfsPassword = data_get($protocol_settings, 'obfs.password');
            if ($obfsType !== 'salamander' || blank($obfsPassword)) {
                return '';
            }
            $salamander = $obfsPassword;
        }

        $addr = Helper::wrapIPv6($server['host']);
        $config = [
            "hysteria2={$addr}:{$server['port']}",
            "password={$password}",
        ];
        if ($salamander !== null) {
            $config[] = "salamander-password={$salamander}";
        }

        if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
            $config[] = "tls-host={$serverName}";
        }
        // tls-verification 必须显式写；面板自签证书（有指纹）时开校验并固定证书指纹
        if (data_get($protocol_settings, 'tls.allow_insecure')) {
            $config[] = 'tls-verification=false';
        } else {
            $config[] = 'tls-verification=true';
            $pin = CertPinHelper::normalizePin(data_get($protocol_settings, 'tls.pinned_peer_cert_sha256'));
            if ($pin !== null) {
                $config[] = "tls-cert-sha256={$pin}";
            }
        }

        // 服务端下行 = 客户端下载，单位 Mbps
        $down = (int) data_get($protocol_settings, 'bandwidth.down', 0);
        if ($down > 0) {
            $config[] = "download-bandwidth={$down}";
        }

        // 端口跳跃：port 是面板从范围里随机挑的一个，ports 是完整范围
        if (!blank($server['ports'] ?? null)) {
            $config[] = 'port-hopping=' . str_replace(' ', '', (string) $server['ports']);
            $hopInterval = (int) data_get($protocol_settings, 'hop_interval', 0);
            if ($hopInterval >= 5 && $hopInterval <= 3600) {
                $config[] = "port-hopping-interval={$hopInterval}";
            }
        }

        $config[] = 'udp-relay=true';
        $config[] = "tag={$server['name']}";

        return implode(',', array_filter($config)) . "\r\n";
    }
}
