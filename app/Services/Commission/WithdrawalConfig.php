<?php

namespace App\Services\Commission;

/**
 * 佣金提现配置快照（全部存于 v2_settings）。
 *
 * 链（chain）是后台可编辑的列表。管理员只需要在 NETWORKS 里挑一个网络，
 * 地址格式预设、区块浏览器链接、通道费就自动带出来——这三样和网络是死绑定的，
 * 让人一个个手填只会填错（TRC20 配上 EVM 正则，用户的地址就永远过不了校验）。
 * 挑 `custom` 才需要自己填。任何一项填了值都以管理员填的为准。
 *
 * 地址格式只在服务端校验，正则通过公开配置一并下发给前端做即时提示，单一真源。
 *
 * 汇率不在这里存值：见 UsdtRateService，后台那个数字只是接口全挂时的兜底。
 */
final class WithdrawalConfig
{
    /** 地址格式预设：正则（PCRE 与 JS 共用同一模式串，不带定界符）与提示 */
    public const PRESETS = [
        'tron' => ['pattern' => '^T[1-9A-HJ-NP-Za-km-z]{33}$', 'hint' => 'T 开头的 34 位地址'],
        'evm' => ['pattern' => '^0x[0-9a-fA-F]{40}$', 'hint' => '0x 开头的 42 位地址'],
        'solana' => ['pattern' => '^[1-9A-HJ-NP-Za-km-z]{32,44}$', 'hint' => 'Base58 编码，32–44 位'],
        'ton' => ['pattern' => '^(EQ|UQ)[A-Za-z0-9_-]{46}$', 'hint' => 'EQ / UQ 开头的 48 位地址'],
        'aptos' => ['pattern' => '^0x[0-9a-fA-F]{1,64}$', 'hint' => '0x 开头的十六进制地址（最长 64 位）'],
        'none' => ['pattern' => null, 'hint' => ''],
    ];

    /**
     * 网络目录：网络 → 展示名 / 地址格式 / 区块浏览器 / 默认通道费（USDT）。
     *
     * 通道费取的是各大交易所提 USDT 时的常见档位，只是默认值，管理员可以按自己实际的
     * 打款成本改。ERC20 贵是因为以太坊主网 gas，TRC20 便宜且到账快，所以放在第一位。
     */
    public const NETWORKS = [
        'trc20' => ['label' => 'TRC20 (Tron)', 'preset' => 'tron', 'explorer_tx' => 'https://tronscan.org/#/transaction/{txid}', 'fee' => 1.0],
        'bep20' => ['label' => 'BEP20 (BNB Smart Chain)', 'preset' => 'evm', 'explorer_tx' => 'https://bscscan.com/tx/{txid}', 'fee' => 0.5],
        'erc20' => ['label' => 'ERC20 (Ethereum)', 'preset' => 'evm', 'explorer_tx' => 'https://etherscan.io/tx/{txid}', 'fee' => 5.0],
        'polygon' => ['label' => 'Polygon (PoS)', 'preset' => 'evm', 'explorer_tx' => 'https://polygonscan.com/tx/{txid}', 'fee' => 0.5],
        'arbitrum' => ['label' => 'Arbitrum One', 'preset' => 'evm', 'explorer_tx' => 'https://arbiscan.io/tx/{txid}', 'fee' => 0.5],
        'optimism' => ['label' => 'Optimism', 'preset' => 'evm', 'explorer_tx' => 'https://optimistic.etherscan.io/tx/{txid}', 'fee' => 0.5],
        'base' => ['label' => 'Base', 'preset' => 'evm', 'explorer_tx' => 'https://basescan.org/tx/{txid}', 'fee' => 0.5],
        'avalanche' => ['label' => 'Avalanche C-Chain', 'preset' => 'evm', 'explorer_tx' => 'https://snowtrace.io/tx/{txid}', 'fee' => 0.5],
        'solana' => ['label' => 'Solana (SPL)', 'preset' => 'solana', 'explorer_tx' => 'https://solscan.io/tx/{txid}', 'fee' => 1.0],
        'ton' => ['label' => 'TON', 'preset' => 'ton', 'explorer_tx' => 'https://tonviewer.com/transaction/{txid}', 'fee' => 0.5],
        'aptos' => ['label' => 'Aptos', 'preset' => 'aptos', 'explorer_tx' => 'https://explorer.aptoslabs.com/txn/{txid}', 'fee' => 0.5],
        'custom' => ['label' => '', 'preset' => 'none', 'explorer_tx' => '', 'fee' => 0.0],
    ];

    /** 开箱即用的三条链，覆盖绝大多数用户 */
    public const DEFAULT_NETWORK_KEYS = ['trc20', 'bep20', 'erc20'];

    public const RATE_SOURCE_AUTO = 'auto';
    public const RATE_SOURCE_MANUAL = 'manual';

    public const DEFAULT_THANKS = '感谢你对我们的支持！佣金已按你提交的地址打款，请注意查收；到账通常需要几分钟到一小时不等。';

    public const DEFAULTS = [
        'withdraw_close_enable' => 0,
        'commission_withdraw_limit' => 100,
        'commission_withdraw_max' => 0,
        'commission_withdraw_chains' => null, // null → 用 defaultChains()
        'commission_withdraw_rate_source' => self::RATE_SOURCE_AUTO,
        'commission_withdraw_usdt_rate' => 0,
        'commission_withdraw_require_qrcode' => 0,
        'commission_withdraw_thanks' => self::DEFAULT_THANKS,
    ];

    /** @var array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}|null */
    private ?array $rateSnapshot = null;

    /**
     * @param array<int, array{code:string,name:string,network_key:string,network:string,preset:string,explorer_tx:string,fee:float,hint:string,pattern:?string}> $chains
     */
    public function __construct(
        public readonly bool $enable,
        public readonly int $minCents,
        public readonly int $maxCents,
        public readonly array $chains,
        public readonly string $rateSource,
        public readonly float $fallbackRate,
        public readonly bool $requireQrcode,
        public readonly string $thanks,
        public readonly string $currency,
        public readonly string $currencySymbol,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn(string $key) => admin_setting($key, self::DEFAULTS[$key]);

        $rateSource = strtolower(trim((string) $get('commission_withdraw_rate_source')));
        if ($rateSource !== self::RATE_SOURCE_MANUAL) {
            $rateSource = self::RATE_SOURCE_AUTO;
        }

        return new self(
            enable: !(bool) $get('withdraw_close_enable'),
            minCents: max(0, (int) round(((float) $get('commission_withdraw_limit')) * 100)),
            maxCents: max(0, (int) round(((float) $get('commission_withdraw_max')) * 100)),
            chains: self::normalizeChains($get('commission_withdraw_chains')),
            rateSource: $rateSource,
            fallbackRate: max(0, (float) $get('commission_withdraw_usdt_rate')),
            requireQrcode: (bool) $get('commission_withdraw_require_qrcode'),
            thanks: trim((string) $get('commission_withdraw_thanks')) ?: self::DEFAULT_THANKS,
            currency: (string) admin_setting('currency', 'CNY'),
            currencySymbol: (string) admin_setting('currency_symbol', '¥'),
        );
    }

    /**
     * @return array<int, array{code:string,name:string,network_key:string,network:string,preset:string,explorer_tx:string,fee:float,hint:string,pattern:?string}>
     */
    public static function defaultChains(): array
    {
        return self::normalizeChains(array_map(
            static fn(string $key) => ['name' => 'USDT', 'network_key' => $key],
            self::DEFAULT_NETWORK_KEYS
        ));
    }

    /**
     * 后台保存的链列表可能是 JSON 字符串、数组，甚至老版本的字符串数组（['USDT','支付宝']）。
     * 统一成带 hint / pattern / fee 的结构；非法项丢弃；code 重复时后者覆盖前者。
     *
     * 网络目录里的条目会补齐管理员没填的字段：老数据只存了 network 文本时，
     * 这里按文本反查网络 key，于是历史配置也能白捡到通道费与浏览器链接。
     *
     * @return array<int, array{code:string,name:string,network_key:string,network:string,preset:string,explorer_tx:string,fee:float,hint:string,pattern:?string}>
     */
    public static function normalizeChains(mixed $raw): array
    {
        // 从没配过 → 默认三条；管理员主动清空（存成 []）→ 就是空，不擅自复活
        if ($raw === null) {
            return self::buildDefaults();
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $chains = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                // 老版本 commission_withdraw_method 的纯名称形态
                $item = ['code' => self::slug($item), 'name' => $item, 'preset' => 'none'];
            }
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $networkKey = self::resolveNetworkKey($item);
            $catalog = self::NETWORKS[$networkKey] ?? null;
            $network = trim((string) ($item['network'] ?? ''));
            if ($network === '' && $catalog) {
                $network = $catalog['label'];
            }
            if ($name === '' && $catalog && $networkKey !== 'custom') {
                $name = 'USDT';
            }
            $code = self::slug((string) ($item['code'] ?? '')) ?: self::defaultCode($name, $networkKey, $network);
            if ($name === '' || $code === '') {
                continue;
            }

            $preset = strtolower(trim((string) ($item['preset'] ?? '')));
            if (!isset(self::PRESETS[$preset])) {
                $preset = $catalog['preset'] ?? 'none';
            }
            $explorer = self::sanitizeExplorer((string) ($item['explorer_tx'] ?? ''));
            if ($explorer === '' && $catalog) {
                $explorer = $catalog['explorer_tx'];
            }
            $fee = $item['fee'] ?? null;
            if (!is_numeric($fee)) {
                $fee = $catalog['fee'] ?? 0.0;
            }

            $chains[$code] = [
                'code' => $code,
                'name' => mb_substr($name, 0, 64),
                'network_key' => $networkKey,
                'network' => mb_substr($network, 0, 64),
                'preset' => $preset,
                'explorer_tx' => mb_substr($explorer, 0, 255),
                'fee' => round(max(0, (float) $fee), 4),
                'hint' => self::PRESETS[$preset]['hint'],
                'pattern' => self::PRESETS[$preset]['pattern'],
            ];
        }

        return array_values($chains);
    }

    /**
     * @return array<int, array{code:string,name:string,network_key:string,network:string,preset:string,explorer_tx:string,fee:float,hint:string,pattern:?string}>
     */
    private static function buildDefaults(): array
    {
        $chains = [];
        foreach (self::DEFAULT_NETWORK_KEYS as $key) {
            $catalog = self::NETWORKS[$key];
            $code = self::defaultCode('USDT', $key);
            $chains[] = [
                'code' => $code,
                'name' => 'USDT',
                'network_key' => $key,
                'network' => $catalog['label'],
                'preset' => $catalog['preset'],
                'explorer_tx' => $catalog['explorer_tx'],
                'fee' => (float) $catalog['fee'],
                'hint' => self::PRESETS[$catalog['preset']]['hint'],
                'pattern' => self::PRESETS[$catalog['preset']]['pattern'],
            ];
        }
        return $chains;
    }

    /**
     * 优先用显式的 network_key；老数据只有 network 文本时按目录里的展示名 / key 反查。
     */
    public static function resolveNetworkKey(array $item): string
    {
        $key = strtolower(trim((string) ($item['network_key'] ?? '')));
        if ($key !== '' && isset(self::NETWORKS[$key])) {
            return $key;
        }

        $network = strtolower(trim((string) ($item['network'] ?? '')));
        if ($network === '') {
            return 'custom';
        }
        foreach (self::NETWORKS as $candidate => $meta) {
            if ($candidate === 'custom') {
                continue;
            }
            if ($network === strtolower($meta['label']) || preg_match('/\b' . preg_quote($candidate, '/') . '\b/', $network)) {
                return $candidate;
            }
        }
        return 'custom';
    }

    /**
     * 链的默认 code。走网络 key 而不是展示名——展示名是给人看的、随时可能改，
     * 而 code 会写进每一条提现记录与用户保存的收款信息，改了就对不上历史数据。
     */
    public static function defaultCode(string $name, string $networkKey, string $networkLabel = ''): string
    {
        if ($networkKey !== '' && $networkKey !== 'custom') {
            return self::slug("{$name}_{$networkKey}");
        }

        // 自定义链：名称 / 网络可能全是中文，slug 会被清成空串或彼此撞车，
        // 这时补一段短哈希，保证每条链都有稳定且唯一的 code
        $slug = self::slug("{$name} {$networkLabel}");
        if ($networkLabel !== '' && $slug === self::slug($name)) {
            $slug .= '_' . substr(md5($networkLabel), 0, 6);
        }
        return $slug !== '' ? $slug : 'chain_' . substr(md5($name . '|' . $networkLabel), 0, 8);
    }

    /**
     * 区块浏览器模板是后台自由文本，最后会变成工单回复与邮件里的链接。
     * 只放行 http(s)，挡掉 javascript: / data: 这类协议。
     */
    public static function sanitizeExplorer(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return preg_match('#^https?://#i', $value) ? $value : '';
    }

    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        return trim(substr($slug, 0, 32), '_');
    }

    public function findChain(string $code): ?array
    {
        foreach ($this->chains as $chain) {
            if ($chain['code'] === $code) {
                return $chain;
            }
        }
        return null;
    }

    public static function addressMatches(array $chain, string $address): bool
    {
        $pattern = $chain['pattern'] ?? null;
        if ($pattern === null) {
            return $address !== '' && mb_strlen($address) <= 255;
        }
        return (bool) preg_match('/' . $pattern . '/', $address);
    }

    /**
     * 当前使用的汇率快照。auto 模式取实时行情，取不到再退到后台兜底值。
     *
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    public function rateSnapshot(): array
    {
        if ($this->rateSnapshot !== null) {
            return $this->rateSnapshot;
        }

        if ($this->rateSource === self::RATE_SOURCE_MANUAL) {
            return $this->rateSnapshot = $this->manualSnapshot();
        }

        $live = (new UsdtRateService())->cached($this->currency);
        if ($live['rate'] === null) {
            return $this->rateSnapshot = $this->manualSnapshot();
        }
        return $this->rateSnapshot = $live;
    }

    /**
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    private function manualSnapshot(): array
    {
        return [
            'rate' => $this->fallbackRate > 0 ? $this->fallbackRate : null,
            'source' => $this->fallbackRate > 0 ? 'manual' : 'none',
            'source_label' => UsdtRateService::SOURCE_LABELS[$this->fallbackRate > 0 ? 'manual' : 'none'],
            'fetched_at' => null,
            'is_live' => false,
            'is_stale' => false,
        ];
    }

    public function usdtRate(): ?float
    {
        return $this->rateSnapshot()['rate'];
    }

    /**
     * 按金额与链算一份 USDT 报价：毛额、通道费、实际到账。
     * 汇率取不到时返回 null 的三项，调用方据此不展示估算。
     *
     * @return array{rate: float|null, gross: string|null, fee: string, net: string|null}
     */
    public function quote(int $amountCents, ?array $chain = null, ?float $rate = null): array
    {
        $rate ??= $this->usdtRate();
        $fee = $chain !== null ? (float) ($chain['fee'] ?? 0) : 0.0;
        if ($rate === null || $rate <= 0) {
            return ['rate' => null, 'gross' => null, 'fee' => self::money($fee), 'net' => null];
        }
        $gross = $amountCents / 100 / $rate;
        return [
            'rate' => round($rate, 4),
            'gross' => self::money($gross),
            'fee' => self::money($fee),
            'net' => self::money(max(0, $gross - $fee)),
        ];
    }

    public static function money(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    /**
     * 链的展示名：`USDT · TRC20 (Tron)`；没有 network 时只有名字。
     */
    public static function chainLabel(array $chain): string
    {
        return ($chain['network'] ?? '') !== '' ? "{$chain['name']} · {$chain['network']}" : $chain['name'];
    }

    public function toPublicArray(): array
    {
        $rate = $this->rateSnapshot();

        return [
            'enable' => $this->enable,
            'min_amount' => $this->minCents,
            'max_amount' => $this->maxCents,
            'usdt_rate' => $rate['rate'],
            'usdt_rate_source' => $rate['source'],
            'usdt_rate_source_label' => $rate['source_label'],
            'usdt_rate_updated_at' => $rate['fetched_at'],
            'usdt_rate_is_live' => $rate['is_live'],
            'require_qrcode' => $this->requireQrcode,
            'currency' => $this->currency,
            'currency_symbol' => $this->currencySymbol,
            'chains' => array_map(static fn(array $c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'network' => $c['network'],
                'label' => self::chainLabel($c),
                'preset' => $c['preset'],
                'hint' => $c['hint'],
                'pattern' => $c['pattern'],
                'explorer_tx' => $c['explorer_tx'],
                'fee' => $c['fee'],
                'fee_currency' => 'USDT',
            ], $this->chains),
        ];
    }

    public function toAdminArray(): array
    {
        return [
            'commission_withdraw_max' => $this->maxCents / 100,
            'commission_withdraw_chains' => array_map(static fn(array $c) => [
                'code' => $c['code'],
                'name' => $c['name'],
                'network_key' => $c['network_key'],
                'network' => $c['network'],
                'preset' => $c['preset'],
                'explorer_tx' => $c['explorer_tx'],
                'fee' => $c['fee'],
            ], $this->chains),
            'commission_withdraw_rate_source' => $this->rateSource,
            'commission_withdraw_usdt_rate' => $this->fallbackRate,
            'commission_withdraw_require_qrcode' => $this->requireQrcode,
            'commission_withdraw_thanks' => $this->thanks,
            'commission_withdraw_presets' => array_map(
                static fn(string $key, array $p) => ['value' => $key, 'hint' => $p['hint']],
                array_keys(self::PRESETS),
                self::PRESETS
            ),
            'commission_withdraw_networks' => array_map(
                static fn(string $key, array $n) => [
                    'value' => $key,
                    'label' => $n['label'],
                    'preset' => $n['preset'],
                    'explorer_tx' => $n['explorer_tx'],
                    'fee' => $n['fee'],
                ],
                array_keys(self::NETWORKS),
                self::NETWORKS
            ),
        ];
    }
}
