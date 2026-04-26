<?php

namespace App\Protocols;

use App\Utils\Helper;
use App\Support\AbstractProtocol;
use App\Models\Server;

class Shadowrocket extends AbstractProtocol
{
    public $flags = ['shadowrocket'];
    public $allowedProtocols = [
        Server::TYPE_SHADOWSOCKS,
        Server::TYPE_VMESS,
        Server::TYPE_VLESS,
        Server::TYPE_TROJAN,
        Server::TYPE_HYSTERIA,
        Server::TYPE_TUIC,
        Server::TYPE_ANYTLS,
        Server::TYPE_SOCKS,
    ];

    protected $protocolRequirements = [
        'shadowrocket.hysteria.protocol_settings.version' => [2 => '1993'],
        'shadowrocket.anytls.base_version' => '2592',
        'shadowrocket.trojan.protocol_settings.network' => [
            'whitelist' => ['tcp', 'ws', 'grpc', 'h2', 'httpupgrade'],
            'strict' => true,
        ],
    ];

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;

        $uri = '';
        //display remaining traffic and expire date
        $upload = round($user['u'] / (1024 * 1024 * 1024), 2);
        $download = round($user['d'] / (1024 * 1024 * 1024), 2);
        $totalTraffic = round($user['transfer_enable'] / (1024 * 1024 * 1024), 2);
        $expiredDate = $user['expired_at'] === null ? 'N/A' : date('Y-m-d', $user['expired_at']);
        $uri .= "STATUS=🚀↑:{$upload}GB,↓:{$download}GB,TOT:{$totalTraffic}GB💡Expires:{$expiredDate}\r\n";
        foreach ($servers as $item) {
            if ($item['type'] === Server::TYPE_SHADOWSOCKS) {
                $uri .= self::buildShadowsocks($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_VMESS) {
                $uri .= self::buildVmess($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_VLESS) {
                $uri .= self::buildVless($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_TROJAN) {
                $uri .= self::buildTrojan($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_HYSTERIA) {
                $uri .= self::buildHysteria($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_TUIC) {
                $uri .= self::buildTuic($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_ANYTLS) {
                $uri .= self::buildAnyTLS($item['password'], $item);
            }
            if ($item['type'] === Server::TYPE_SOCKS) {
                $uri .= self::buildSocks($item['password'], $item);
            }
        }
        return response(base64_encode($uri))
            ->header('content-type', 'text/plain');
    }


    public static function buildShadowsocks($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $password = data_get($server, 'password', $password);
        $str = str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode(data_get($protocol_settings, 'cipher') . ":{$password}")
        );
        $addr = Helper::wrapIPv6($server['host']);

        $uri = "ss://{$str}@{$addr}:{$server['port']}";
        $plugin = data_get($protocol_settings, 'plugin') == 'obfs' ? 'obfs-local' : data_get($protocol_settings, 'plugin');
        $plugin_opts = data_get($protocol_settings, 'plugin_opts');
        if ($plugin && $plugin_opts) {
            $uri .= '/?' . 'plugin=' . $plugin . ';' . rawurlencode($plugin_opts);
        }
        return $uri . "#{$name}\r\n";
    }

    public static function buildVmess($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $userinfo = base64_encode('auto:' . $uuid . '@' . Helper::wrapIPv6($server['host']) . ':' . $server['port']);
        $config = [
            'tfo' => 1,
            'remark' => $server['name'],
            'alterId' => 0
        ];
        if (data_get($protocol_settings, 'tls')) {
            $config['tls'] = 1;
            if (data_get($protocol_settings, 'tls_settings')) {
                if (!!data_get($protocol_settings, 'tls_settings.allow_insecure'))
                    $config['allowInsecure'] = (int) data_get($protocol_settings, 'tls_settings.allow_insecure');
                if (!!data_get($protocol_settings, 'tls_settings.server_name'))
                    $config['peer'] = data_get($protocol_settings, 'tls_settings.server_name');
                if ($ech = Helper::normalizeEchSettings(data_get($protocol_settings, 'tls_settings.ech'))) {
                    if ($echConfig = Helper::toMihomoEchConfig(data_get($ech, 'config'))) {
                        $config['ech'] = $echConfig;
                    }
                }
            }
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                if (data_get($protocol_settings, 'network_settings.header.type', 'none') !== 'none') {
                    $config['obfs'] = data_get($protocol_settings, 'network_settings.header.type');
                    $config['path'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.path', ['/']));
                    $config['obfsParam'] = \Illuminate\Support\Arr::random(data_get($protocol_settings, 'network_settings.header.request.headers.Host', ['www.example.com']));
                }
                break;
            case 'ws':
                $config['obfs'] = "websocket";
                $config['path'] = data_get($protocol_settings, 'network_settings.path');
                if ($host = data_get($protocol_settings, 'network_settings.headers.Host')) {
                    $config['obfsParam'] = $host;
                }
                break;
            case 'grpc':
                $config['obfs'] = "grpc";
                $config['path'] = data_get($protocol_settings, 'network_settings.serviceName');
                $config['host'] = data_get($protocol_settings, 'tls_settings.server_name') ?? $server['host'];
                break;
            case 'httpupgrade':
                $config['obfs'] = "httpupgrade";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host'])) {
                    $config['obfsParam'] = $host;
                }
                break;
            case 'h2':
                $config['obfs'] = "h2";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host')) {
                    $config['obfsParam'] = $host[0] ?? $server['host'];
                    $config['peer'] = $host [0] ?? $server['host'];
                }
                break;
            case 'xhttp':
                $config['obfs'] = "xhttp";
                if ($path = data_get($protocol_settings, 'network_settings.path')) {
                    $config['path'] = $path;
                }
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host'])) {
                    $config['obfsParam'] = $host;
                }
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto')) {
                    $config['mode'] = $mode;
                }
                break;
        }
        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vmess://{$userinfo}?{$query}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildVless($uuid, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = Helper::encodeURIComponent($server['name']);
        $host = Helper::wrapIPv6($server['host']);
        $port = $server['port'];

        // 使用标准 VLESS URI 格式 (与 v2board Helper::buildVlessUri 一致)
        // vless://UUID@host:port?type=...&encryption=...&security=...#name
        $config = [
            'type' => data_get($protocol_settings, 'network', 'tcp'),
            'encryption' => 'none',
            'host' => '',
            'path' => '',
            'headerType' => 'none',
            'quicSecurity' => 'none',
            'serviceName' => '',
            'security' => '',
            'flow' => data_get($protocol_settings, 'flow', ''),
            'tfo' => 1,
        ];

        // 处理 TLS
        switch ((int) data_get($protocol_settings, 'tls')) {
            case 1:
                $config['security'] = 'tls';
                $config['sni'] = data_get($protocol_settings, 'tls_settings.server_name', '');
                $config['allowInsecure'] = (int) data_get($protocol_settings, 'tls_settings.allow_insecure', 0);
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                } else {
                    $config['fp'] = 'chrome';
                }
                if ($ech = Helper::normalizeEchSettings(data_get($protocol_settings, 'tls_settings.ech'))) {
                    if ($echConfig = Helper::toMihomoEchConfig(data_get($ech, 'config'))) {
                        $config['ech'] = $echConfig;
                    }
                }
                break;
            case 2:
                $config['security'] = 'reality';
                $config['sni'] = data_get($protocol_settings, 'reality_settings.server_name', '');
                $config['pbk'] = data_get($protocol_settings, 'reality_settings.public_key', '');
                $config['sid'] = data_get($protocol_settings, 'reality_settings.short_id', '');
                if ($fp = Helper::getTlsFingerprint(data_get($protocol_settings, 'utls'))) {
                    $config['fp'] = $fp;
                } else {
                    $config['fp'] = 'chrome';
                }
                break;
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
            $config['encryption'] = $encStr;
        }

        // 处理传输协议
        $networkSettings = data_get($protocol_settings, 'network_settings', []);
        switch (data_get($protocol_settings, 'network')) {
            case 'tcp':
                $header = data_get($networkSettings, 'header', []);
                if (($header['type'] ?? '') === 'http') {
                    $config['headerType'] = 'http';
                    $config['host'] = $header['request']['headers']['Host'][0] ?? '';
                    $config['path'] = $header['request']['path'][0] ?? '';
                }
                break;
            case 'ws':
                $config['path'] = data_get($networkSettings, 'path', '');
                $config['host'] = data_get($networkSettings, 'headers.Host', '');
                break;
            case 'grpc':
                $config['serviceName'] = data_get($networkSettings, 'serviceName', '');
                break;
            case 'kcp':
                $config['headerType'] = data_get($networkSettings, 'header.type', 'none');
                if ($seed = data_get($networkSettings, 'seed')) {
                    $config['seed'] = $seed;
                }
                break;
            case 'h2':
                $config['type'] = 'http';
                $config['path'] = data_get($networkSettings, 'path', '');
                $h2Host = data_get($networkSettings, 'host', '');
                $config['host'] = is_array($h2Host) ? implode(',', $h2Host) : $h2Host;
                break;
            case 'httpupgrade':
                $config['path'] = data_get($networkSettings, 'path', '');
                $config['host'] = data_get($networkSettings, 'host', '');
                break;
            case 'xhttp':
                $config['path'] = data_get($networkSettings, 'path', '');
                $config['host'] = data_get($networkSettings, 'host', '');
                $config['mode'] = data_get($networkSettings, 'mode', 'auto');
                if ($extra = data_get($networkSettings, 'extra')) {
                    $config['extra'] = json_encode($extra, JSON_UNESCAPED_SLASHES);
                }
                break;
        }

        $query = http_build_query($config, '', '&', PHP_QUERY_RFC3986);
        $uri = "vless://{$uuid}@{$host}:{$port}?{$query}#{$name}\r\n";
        return $uri;
    }

    public static function buildTrojan($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $params = [];
        $tlsMode = (int) data_get($protocol_settings, 'tls', 1);

        switch ($tlsMode) {
            case 2: // Reality
                $params['security'] = 'reality';
                $params['pbk'] = data_get($protocol_settings, 'reality_settings.public_key');
                $params['sid'] = data_get($protocol_settings, 'reality_settings.short_id');
                $params['sni'] = data_get($protocol_settings, 'reality_settings.server_name');
                break;
            default: // Standard TLS
                $params['allowInsecure'] = (int) data_get($protocol_settings, 'tls_settings.allow_insecure');
                if ($serverName = data_get($protocol_settings, 'tls_settings.server_name')) {
                    $params['peer'] = $serverName;
                }
                break;
        }

        switch (data_get($protocol_settings, 'network')) {
            case 'grpc':
                $params['obfs'] = 'grpc';
                $params['path'] = data_get($protocol_settings, 'network_settings.serviceName');
                break;
            case 'ws':
                $host = data_get($protocol_settings, 'network_settings.headers.Host');
                $path = data_get($protocol_settings, 'network_settings.path');
                $params['plugin'] = "obfs-local;obfs=websocket;obfs-host={$host};obfs-uri={$path}";
                break;
            case 'h2':
                $params['obfs'] = 'h2';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = is_array($host) ? $host[0] : $host;
                break;
            case 'httpupgrade':
                $params['obfs'] = 'httpupgrade';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = $host;
                break;
            case 'xhttp':
                $params['obfs'] = 'xhttp';
                if ($path = data_get($protocol_settings, 'network_settings.path'))
                    $params['path'] = $path;
                if ($host = data_get($protocol_settings, 'network_settings.host', $server['host']))
                    $params['obfsParam'] = $host;
                if ($mode = data_get($protocol_settings, 'network_settings.mode', 'auto'))
                    $params['mode'] = $mode;
                break;
        }
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);

        $uri = "trojan://{$password}@{$addr}:{$server['port']}?{$query}&tfo=1#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildHysteria($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $uri = ''; // 初始化变量

        switch (data_get($protocol_settings, 'version')) {
            case 1:
                $params = [
                    "auth" => $password,
                    "upmbps" => data_get($protocol_settings, 'bandwidth.up'),
                    "downmbps" => data_get($protocol_settings, 'bandwidth.down'),
                    "protocol" => 'udp',
                    "fastopen" => 1,
                ];
                if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
                    $params['peer'] = $serverName;
                }
                if (data_get($protocol_settings, 'obfs.open')) {
                    $params["obfs"] = "xplus";
                    $params["obfsParam"] = data_get($protocol_settings, 'obfs.password');
                }
                $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure');
                if (isset($server['ports']))
                    $params['mport'] = $server['ports'];
                $query = http_build_query($params);
                $addr = Helper::wrapIPv6($server['host']);

                $uri = "hysteria://{$addr}:{$server['port']}?{$query}#{$server['name']}";
                $uri .= "\r\n";
                break;
            case 2:
                $params = [
                    "obfs" => 'none',
                    "fastopen" => 1
                ];
                if ($serverName = data_get($protocol_settings, 'tls.server_name')) {
                    $params['peer'] = $serverName;
                }
                if (data_get($protocol_settings, 'obfs.open')) {
                    $params['obfs'] = data_get($protocol_settings, 'obfs.type');
                    $params['obfs-password'] = data_get($protocol_settings, 'obfs.password');
                }
                $params['insecure'] = data_get($protocol_settings, 'tls.allow_insecure');
                if (isset($protocol_settings['hop_interval'])) {
                    $params['keepalive'] = data_get($protocol_settings, 'hop_interval');
                }
                if (isset($server['ports'])) {
                    $params['mport'] = $server['ports'];
                }
                $query = http_build_query($params);
                $addr = Helper::wrapIPv6($server['host']);

                $uri = "hysteria2://{$password}@{$addr}:{$server['port']}?{$query}#{$server['name']}";
                $uri .= "\r\n";
                break;
        }
        return $uri;
    }
    public static function buildTuic($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $params = [
            'alpn' => data_get($protocol_settings, 'alpn'),
            'sni' => data_get($protocol_settings, 'tls.server_name'),
            'insecure' => data_get($protocol_settings, 'tls.allow_insecure')
        ];
        if (data_get($protocol_settings, 'version') === 4) {
            $params['token'] = $password;
        } else {
            $params['uuid'] = $password;
            $params['password'] = $password;
        }
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);
        $uri = "tuic://{$addr}:{$server['port']}?{$query}#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildAnyTLS($password, $server)
    {
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $params = [
            'sni' => data_get($protocol_settings, 'tls.server_name'),
            'insecure' => data_get($protocol_settings, 'tls.allow_insecure')
        ];
        $query = http_build_query($params);
        $addr = Helper::wrapIPv6($server['host']);
        $uri = "anytls://{$password}@{$addr}:{$server['port']}?{$query}#{$name}";
        $uri .= "\r\n";
        return $uri;
    }

    public static function buildSocks($password, $server)
    {   
        $protocol_settings = $server['protocol_settings'];
        $name = rawurlencode($server['name']);
        $addr = Helper::wrapIPv6($server['host']);
        $uri = 'socks://' . base64_encode("{$password}:{$password}@{$addr}:{$server['port']}") . "?method=auto#{$name}";
        $uri .= "\r\n";
        return $uri;
    }
}
