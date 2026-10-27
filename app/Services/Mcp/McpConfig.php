<?php

namespace App\Services\Mcp;

/**
 * 客户 AI 助手（MCP）的站点设置快照。
 *
 * 客户把接入地址填进自己的 AI：ChatGPT、Claude、Grok 这类网页版走一键授权（OAuth，见 McpOAuthServer），
 * 在网站授权页点「允许」就连上；OpenClaw、Hermes Agent 这类自己装的智能体在网站生成令牌填进配置。
 * 两种方式拿到的是同一种令牌，只认 /mcp，面板接口一律拒绝（见 User 中间件）。AI 能查账户、按客户的话下单。
 *
 * mcp_enable 为 0（默认）= 整个功能关闭：/mcp 一律 403，面板入口显示「未开放」，下单 / 付款与上线前逐字相同。
 * 打开后所有付过款的账号都能用，不设名单。
 */
final class McpConfig
{
    public const DEFAULTS = [
        'mcp_enable' => 0,
        // 客户填进 AI 的地址（完整 URL，含 /mcp）；为空时按 app_url 拼
        'mcp_endpoint_url' => '',
        // 允许 AI 替客户操作（总闸）；关掉后 mcp:order 令牌也只剩查询
        'mcp_order_enable' => 1,
        // 每个令牌每天最多建几单（新购 / 续费 / 换套餐 / 充值合计）
        'mcp_daily_order_limit' => 10,
        // 每个令牌的调用频率
        'mcp_rate_per_minute' => 60,
        'mcp_rate_per_day' => 2000,
        // 令牌多少天没用就失效
        'mcp_token_idle_days' => 90,
        // 一键授权（OAuth）：ChatGPT、Claude、Grok 网页版只认这种方式；关掉后只能手动生成令牌
        'mcp_oauth_enable' => 1,
    ];

    /** 每个账号最多同时持有的令牌数 */
    public const MAX_TOKENS_PER_USER = 5;

    public function __construct(
        public readonly bool $enabled,
        public readonly string $endpointUrl,
        public readonly bool $orderEnabled,
        public readonly int $dailyOrderLimit,
        public readonly int $ratePerMinute,
        public readonly int $ratePerDay,
        public readonly int $tokenIdleDays,
        public readonly bool $oauthEnabled = true,
    ) {
    }

    public static function fromSettings(): self
    {
        $get = static fn (string $key) => admin_setting($key, self::DEFAULTS[$key]);

        return new self(
            enabled: (bool) (int) $get('mcp_enable'),
            endpointUrl: self::endpointFrom((string) $get('mcp_endpoint_url')),
            orderEnabled: (bool) (int) $get('mcp_order_enable'),
            dailyOrderLimit: max(0, min(100, (int) $get('mcp_daily_order_limit'))),
            ratePerMinute: max(1, min(600, (int) $get('mcp_rate_per_minute'))),
            ratePerDay: max(1, min(100000, (int) $get('mcp_rate_per_day'))),
            tokenIdleDays: max(1, min(365, (int) $get('mcp_token_idle_days'))),
            oauthEnabled: (bool) (int) $get('mcp_oauth_enable'),
        );
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
            'mcp_endpoint_url' => (string) admin_setting('mcp_endpoint_url', ''),
            'mcp_order_enable' => $this->orderEnabled,
            'mcp_daily_order_limit' => $this->dailyOrderLimit,
            'mcp_rate_per_minute' => $this->ratePerMinute,
            'mcp_rate_per_day' => $this->ratePerDay,
            'mcp_token_idle_days' => $this->tokenIdleDays,
            'mcp_oauth_enable' => $this->oauthEnabled,
        ];
    }

    /** 一键授权此刻是否可用：总开关、授权开关都开着，且接入地址是完整网址 */
    public function oauthActive(): bool
    {
        return $this->enabled && $this->oauthEnabled && $this->issuer() !== null;
    }

    /** 授权服务器标识（issuer）= 接入地址的源（https://host[:port]），ChatGPT 按它逐字比对 */
    public function issuer(): ?string
    {
        $parts = parse_url($this->endpointUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
