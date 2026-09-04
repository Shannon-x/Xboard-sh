<?php

namespace App\Services\Commission;

/**
 * 佣金提现配置快照（全部存于 v2_settings）。
 *
 * 链（chain）是后台可编辑的列表：每条有 code / 展示名 / 网络 / 地址格式预设 / 区块浏览器交易链接模板。
 * 地址格式只在服务端校验，正则通过公开配置一并下发给前端做即时提示，单一真源。
 */
final class WithdrawalConfig
{
    /** 地址格式预设：正则（PCRE 与 JS 共用同一模式串，不带定界符）与提示 */
    public const PRESETS = [
        'tron' => ['pattern' => '^T[1-9A-HJ-NP-Za-km-z]{33}$', 'hint' => 'T 开头的 34 位地址'],
        'evm' => ['pattern' => '^0x[0-9a-fA-F]{40}$', 'hint' => '0x 开头的 42 位地址'],
        'solana' => ['pattern' => '^[1-9A-HJ-NP-Za-km-z]{32,44}$', 'hint' => 'Base58 编码，32–44 位'],
        'ton' => ['pattern' => '^(EQ|UQ)[A-Za-z0-9_-]{46}$', 'hint' => 'EQ / UQ 开头的 48 位地址'],
        'none' => ['pattern' => null, 'hint' => ''],
    ];

    public const DEFAULT_CHAINS = [
        ['code' => 'usdt_trc20', 'name' => 'USDT', 'network' => 'TRC20 (Tron)', 'preset' => 'tron', 'explorer_tx' => 'https://tronscan.org/#/transaction/{txid}'],
        ['code' => 'usdt_bep20', 'name' => 'USDT', 'network' => 'BEP20 (BNB Smart Chain)', 'preset' => 'evm', 'explorer_tx' => 'https://bscscan.com/tx/{txid}'],
        ['code' => 'usdt_erc20', 'name' => 'USDT', 'network' => 'ERC20 (Ethereum)', 'preset' => 'evm', 'explorer_tx' => 'https://etherscan.io/tx/{txid}'],
    ];

    public const DEFAULT_THANKS = '感谢你对我们的支持！佣金已按你提交的地址打款，请注意查收；到账通常需要几分钟到一小时不等。';

    public const DEFAULTS = [
        'withdraw_close_enable' => 0,
        'commission_withdraw_limit' => 100,
        'commission_withdraw_max' => 0,
        'commission_withdraw_chains' => self::DEFAULT_CHAINS,
        'commission_withdraw_usdt_rate' => 7.2,
        'commission_withdraw_require_qrcode' => 0,
        'commission_withdraw_thanks' => self::DEFAULT_THANKS,
    ];

    /**
     * @param array<int, array{code:string,name:string,network:string,preset:string,explorer_tx:string,hint:string,pattern:?string}> $chains
     */
    public function __construct(
        public readonly bool $enable,
        public readonly int $minCents,
        public readonly int $maxCents,
        public readonly array $chains,
        public readonly float $usdtRate,
        public readonly bool $requireQrcode,
        public readonly string $thanks,
        public readonly string $currency,
        public readonly string $currencySymbol,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn(string $key) => admin_setting($key, self::DEFAULTS[$key]);

        return new self(
            enable: !(bool) $get('withdraw_close_enable'),
            minCents: max(0, (int) round(((float) $get('commission_withdraw_limit')) * 100)),
            maxCents: max(0, (int) round(((float) $get('commission_withdraw_max')) * 100)),
            chains: self::normalizeChains($get('commission_withdraw_chains')),
            usdtRate: max(0, (float) $get('commission_withdraw_usdt_rate')),
            requireQrcode: (bool) $get('commission_withdraw_require_qrcode'),
            thanks: trim((string) $get('commission_withdraw_thanks')) ?: self::DEFAULT_THANKS,
            currency: (string) admin_setting('currency', 'CNY'),
            currencySymbol: (string) admin_setting('currency_symbol', '¥'),
        );
    }

    /**
     * 后台保存的链列表可能是 JSON 字符串、数组，甚至老版本的字符串数组（['USDT','支付宝']）。
     * 统一成带 hint / pattern 的结构；非法项丢弃；code 重复时后者覆盖前者。
     *
     * @return array<int, array{code:string,name:string,network:string,preset:string,explorer_tx:string,hint:string,pattern:?string}>
     */
    public static function normalizeChains(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
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
            $code = self::slug((string) ($item['code'] ?? $name));
            if ($name === '' || $code === '') {
                continue;
            }
            $preset = strtolower(trim((string) ($item['preset'] ?? 'none')));
            if (!isset(self::PRESETS[$preset])) {
                $preset = 'none';
            }
            $chains[$code] = [
                'code' => $code,
                'name' => mb_substr($name, 0, 64),
                'network' => mb_substr(trim((string) ($item['network'] ?? '')), 0, 64),
                'preset' => $preset,
                'explorer_tx' => mb_substr(trim((string) ($item['explorer_tx'] ?? '')), 0, 255),
                'hint' => self::PRESETS[$preset]['hint'],
                'pattern' => self::PRESETS[$preset]['pattern'],
            ];
        }

        return array_values($chains);
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

    public function estimateUsdt(int $amountCents): ?string
    {
        if ($this->usdtRate <= 0) {
            return null;
        }
        return number_format($amountCents / 100 / $this->usdtRate, 4, '.', '');
    }

    /**
     * 链的展示名：`USDT · TRC20 (Tron)`；没有 network 时只有名字。
     */
    public static function chainLabel(array $chain): string
    {
        return $chain['network'] !== '' ? "{$chain['name']} · {$chain['network']}" : $chain['name'];
    }

    public function toPublicArray(): array
    {
        return [
            'enable' => $this->enable,
            'min_amount' => $this->minCents,
            'max_amount' => $this->maxCents,
            'usdt_rate' => $this->usdtRate,
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
                'network' => $c['network'],
                'preset' => $c['preset'],
                'explorer_tx' => $c['explorer_tx'],
            ], $this->chains),
            'commission_withdraw_usdt_rate' => $this->usdtRate,
            'commission_withdraw_require_qrcode' => $this->requireQrcode,
            'commission_withdraw_thanks' => $this->thanks,
            'commission_withdraw_presets' => array_map(
                static fn(string $key, array $p) => ['value' => $key, 'hint' => $p['hint']],
                array_keys(self::PRESETS),
                self::PRESETS
            ),
        ];
    }
}
