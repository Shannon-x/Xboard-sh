<?php

namespace App\Services\Mcp;

/**
 * 客户 AI 助手（MCP）的站点设置快照。
 *
 * 客户在面板「AI 助手」里生成一个令牌，填进自己的 AI（Claude 自定义连接器、Cursor、Cherry Studio……），
 * AI 就能通过 /mcp 查账户、按客户的话下单。令牌只认 /mcp，面板接口一律拒绝（见 User 中间件）。
 *
 * mcp_enable 为 0（默认）= 整个功能关闭：/mcp 一律 403，面板卡片显示「未开放」，下单 / 付款与上线前逐字相同。
 * 白名单非空时只有名单里的账号能生成令牌（试点）；为空时开放给「付过款」的账号。
 */
final class McpConfig
{
    public const DEFAULTS = [
        'mcp_enable' => 0,
        // 试点名单：邮箱或用户 ID，逗号 / 换行分隔；为空 = 所有付过款的账号
        'mcp_whitelist' => '',
        // 客户填进 AI 的地址（完整 URL，含 /mcp）；为空时按 app_url 拼
        'mcp_endpoint_url' => '',
        // 允许 AI 下单（总闸）；关掉后 mcp:order 令牌也只剩查询
        'mcp_order_enable' => 1,
        // 每个令牌每天最多建几单（新购 / 续费 / 换套餐 / 充值合计）
        'mcp_daily_order_limit' => 10,
        // 每个令牌的调用频率
        'mcp_rate_per_minute' => 60,
        'mcp_rate_per_day' => 2000,
        // 令牌多少天没用就失效
        'mcp_token_idle_days' => 90,
    ];

    /** 每个账号最多同时持有的令牌数 */
    public const MAX_TOKENS_PER_USER = 5;

    /**
     * @param list<string> $whitelist 小写邮箱或数字 ID 字符串
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $whitelist,
        public readonly string $endpointUrl,
        public readonly bool $orderEnabled,
        public readonly int $dailyOrderLimit,
        public readonly int $ratePerMinute,
        public readonly int $ratePerDay,
        public readonly int $tokenIdleDays,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn (string $key) => admin_setting($key, self::DEFAULTS[$key]);

        return new self(
            enabled: (bool) (int) $get('mcp_enable'),
            whitelist: self::parseWhitelist((string) $get('mcp_whitelist')),
            endpointUrl: self::endpointFrom((string) $get('mcp_endpoint_url')),
            orderEnabled: (bool) (int) $get('mcp_order_enable'),
            dailyOrderLimit: max(0, min(100, (int) $get('mcp_daily_order_limit'))),
            ratePerMinute: max(1, min(600, (int) $get('mcp_rate_per_minute'))),
            ratePerDay: max(1, min(100000, (int) $get('mcp_rate_per_day'))),
            tokenIdleDays: max(1, min(365, (int) $get('mcp_token_idle_days'))),
        );
    }

    /** @return list<string> */
    public static function parseWhitelist(string $raw): array
    {
        $items = preg_split('/[\s,，;；]+/u', mb_strtolower(trim($raw))) ?: [];
        return array_values(array_unique(array_filter($items, fn ($v) => $v !== '')));
    }

    private static function endpointFrom(string $configured): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return $configured;
        }
        $base = rtrim((string) admin_setting('app_url', ''), '/');
        return $base === '' ? '' : $base . '/mcp';
    }

    /** 后台「系统设置 → AI 助手」的回显 */
    public function toAdminArray(): array
    {
        return [
            'mcp_enable' => $this->enabled,
            'mcp_whitelist' => (string) admin_setting('mcp_whitelist', ''),
            'mcp_endpoint_url' => (string) admin_setting('mcp_endpoint_url', ''),
            'mcp_order_enable' => $this->orderEnabled,
            'mcp_daily_order_limit' => $this->dailyOrderLimit,
            'mcp_rate_per_minute' => $this->ratePerMinute,
            'mcp_rate_per_day' => $this->ratePerDay,
            'mcp_token_idle_days' => $this->tokenIdleDays,
        ];
    }
}
