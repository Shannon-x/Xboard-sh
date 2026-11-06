<?php

namespace App\Support;

use App\Models\Server;
use App\Services\Plugin\HookManager;

abstract class AbstractProtocol
{
    /**
     * @var array 用户信息
     */
    protected $user;

    /**
     * @var array 服务器信息
     */
    protected $servers;

    /**
     * @var string|null 客户端名称
     */
    protected $clientName;

    /**
     * @var string|null 客户端版本
     */
    protected $clientVersion;

    /**
     * @var string|null 原始 User-Agent
     */
    protected $userAgent;

    /**
     * @var array 协议标识
     */
    public $flags = [];

    /**
     * @var array 协议需求配置
     */
    protected $protocolRequirements = [];

    /**
     * @var array 允许的协议类型（白名单） 为空则不进行过滤
     */
    protected $allowedProtocols = [];

    /**
     * @var array AnyTLS 外层传输白名单（network 为空视同 tcp）
     *
     * AnyTLS 跑在 ws / grpc / httpupgrade / xhttp 上只有 Xray 内核节点（V2bX / v2node）实现，
     * mihomo / sing-box / Surge 等客户端的 anytls 出站都没有传输层选项：
     * 照常下发只会多出一条握手必失败的节点，所以默认只放行 tcp。
     * 哪个客户端将来支持了，在对应子类覆盖本属性，并在它的 buildAnyTLS 里输出传输参数。
     */
    protected $anyTlsTransports = ['tcp'];

    /**
     * @var array 能用 VLESS Encryption（如 mlkem768x25519plus）的客户端 => 最低版本，'0.0.0' 表示不限版本
     *
     * 默认为空：这个下发格式不下发开了 VLESS Encryption 的节点。不认识 encryption 的客户端要么整份配置报错
     * （官方 sing-box 拒绝未知字段），要么把节点当普通 VLESS 导入、连不上也不报错（NekoBox 等），
     * 所以只对核实过的客户端放行。客户端名或版本识别不出来时同样不下发。
     */
    protected $vlessEncryptionClients = [];

    /**
     * 构造函数
     *
     * @param array $user 用户信息
     * @param array $servers 服务器信息
     * @param string|null $clientName 客户端名称
     * @param string|null $clientVersion 客户端版本
     * @param string|null $userAgent 原始 User-Agent
     */
    public function __construct($user, $servers, $clientName = null, $clientVersion = null, $userAgent = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->clientName = $clientName;
        $this->clientVersion = $clientVersion;
        $this->userAgent = $userAgent;
        $this->protocolRequirements = $this->normalizeProtocolRequirements($this->protocolRequirements);
        $this->servers = HookManager::filter('protocol.servers.filtered', $this->filterServersByVersion());
    }

    /**
     * 获取协议标识
     *
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * 处理请求
     *
     * @return mixed
     */
    abstract public function handle();

    /**
     * 根据客户端版本过滤不兼容的服务器
     *
     * @return array
     */
    protected function filterServersByVersion()
    {
        $this->filterByAllowedProtocols();
        $this->filterAnyTlsTransports();
        $this->filterVlessEncryption();
        $hasGlobalConfig = isset($this->protocolRequirements['*']);
        $hasClientConfig = isset($this->protocolRequirements[$this->clientName]);

        if ((blank($this->clientName) || blank($this->clientVersion)) && !$hasGlobalConfig) {
            return $this->servers;
        }

        if (!$hasGlobalConfig && !$hasClientConfig) {
            return $this->servers;
        }

        return collect($this->servers)
            ->filter(fn($server) => $this->isCompatible($server))
            ->values()
            ->all();
    }

    /**
     * 检查服务器是否与当前客户端兼容
     *
     * @param array $server 服务器信息
     * @return bool
     */
    protected function isCompatible($server)
    {
        $serverType = $server['type'] ?? null;
        if (isset($this->protocolRequirements['*'][$serverType])) {
            $globalRequirements = $this->protocolRequirements['*'][$serverType];
            if (!$this->checkRequirements($globalRequirements, $server)) {
                return false;
            }
        }

        if (!isset($this->protocolRequirements[$this->clientName][$serverType])) {
            return true;
        }

        $requirements = $this->protocolRequirements[$this->clientName][$serverType];
        return $this->checkRequirements($requirements, $server);
    }

    /**
     * 检查版本要求
     *
     * @param array $requirements 要求配置
     * @param array $server 服务器信息
     * @return bool
     */
    private function checkRequirements(array $requirements, array $server): bool
    {
        foreach ($requirements as $field => $filterRule) {
            if (in_array($field, ['base_version', 'incompatible'])) {
                continue;
            }

            $actualValue = data_get($server, $field);

            if (is_array($filterRule) && isset($filterRule['whitelist'])) {
                $allowedValues = $filterRule['whitelist'];
                $strict = $filterRule['strict'] ?? false;
                if (!empty($allowedValues) && is_int(array_key_first($allowedValues))) {
                    $allowedValues = array_fill_keys($allowedValues, '0.0.0');
                }
                if ($strict) {
                    if ($actualValue === null) {
                        return false;
                    }
                    if (!is_string($actualValue) && !is_int($actualValue)) {
                        return false;
                    }
                    if (!isset($allowedValues[$actualValue])) {
                        return false;
                    }
                    $requiredVersion = $allowedValues[$actualValue];
                    if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                        return false;
                    }
                    continue;
                }
            } else {
                $allowedValues = $filterRule;
                $strict = false;
            }

            if ($actualValue === null) {
                continue;
            }
            if (!is_string($actualValue) && !is_int($actualValue)) {
                continue;
            }
            if (!isset($allowedValues[$actualValue])) {
                continue;
            }
            $requiredVersion = $allowedValues[$actualValue];
            if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查当前客户端是否支持特定功能
     *
     * @param string $clientName 客户端名称
     * @param string $minVersion 最低版本要求
     * @param array $additionalConditions 额外条件检查
     * @return bool
     */
    protected function supportsFeature(string $clientName, string $minVersion, array $additionalConditions = []): bool
    {
        // 检查客户端名称
        if ($this->clientName !== $clientName) {
            return false;
        }

        // 检查版本号
        if (empty($this->clientVersion) || version_compare($this->clientVersion, $minVersion, '<')) {
            return false;
        }

        // 检查额外条件
        foreach ($additionalConditions as $condition) {
            if (!$condition) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据白名单过滤服务器
     *
     * @return void
     */
    protected function filterByAllowedProtocols(): void
    {
        if (!empty($this->allowedProtocols)) {
            $this->servers = collect($this->servers)
                ->filter(fn($server) => in_array($server['type'], $this->allowedProtocols))
                ->values()
                ->all();
        }
    }

    /**
     * 过滤掉当前客户端不支持其外层传输的 AnyTLS 节点
     *
     * @return void
     */
    protected function filterAnyTlsTransports(): void
    {
        $this->servers = collect($this->servers)
            ->filter(function ($server) {
                if (($server['type'] ?? null) !== Server::TYPE_ANYTLS) {
                    return true;
                }
                $network = strtolower(trim((string) data_get($server, 'protocol_settings.network')));
                return in_array($network === '' ? 'tcp' : $network, $this->anyTlsTransports, true);
            })
            ->values()
            ->all();
    }

    /**
     * 过滤掉当前客户端用不了的 VLESS Encryption 节点
     *
     * @return void
     */
    protected function filterVlessEncryption(): void
    {
        if ($this->clientSupportsVlessEncryption()) {
            return;
        }
        $this->servers = collect($this->servers)
            ->reject(fn($server) => self::usesVlessEncryption($server))
            ->values()
            ->all();
    }

    /**
     * 节点是否开了 VLESS Encryption
     */
    public static function usesVlessEncryption($server): bool
    {
        if (($server['type'] ?? null) !== Server::TYPE_VLESS) {
            return false;
        }
        $encryption = strtolower(trim((string) data_get($server, 'protocol_settings.encryption')));
        return $encryption !== '' && $encryption !== 'none';
    }

    /**
     * 当前客户端是否在 $vlessEncryptionClients 里且版本够
     */
    protected function clientSupportsVlessEncryption(): bool
    {
        $clientName = strtolower((string) $this->clientName);
        if ($clientName === '' || !array_key_exists($clientName, $this->vlessEncryptionClients)) {
            return false;
        }
        $minVersion = (string) $this->vlessEncryptionClients[$clientName];
        if ($minVersion === '0.0.0') {
            return true;
        }
        return !blank($this->clientVersion) && version_compare($this->clientVersion, $minVersion, '>=');
    }

    /**
     * 将平铺的协议需求转换为树形结构
     *
     * @param array $flat 平铺的协议需求
     * @return array 树形结构的协议需求
     */
    protected function normalizeProtocolRequirements(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (!str_contains($key, '.')) {
                $result[$key] = $value;
                continue;
            }
            $segments = explode('.', $key, 3);
            if (count($segments) < 3) {
                $result[$segments[0]][$segments[1] ?? '*'][''] = $value;
                continue;
            }
            [$client, $type, $field] = $segments;
            $result[$client][$type][$field] = $value;
        }
        return $result;
    }
}
