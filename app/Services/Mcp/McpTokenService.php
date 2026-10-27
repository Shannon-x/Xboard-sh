<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\McpCallLog;
use App\Models\McpOauthGrant;
use App\Models\Order;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 客户 AI 助手（MCP）令牌：谁能生成、生成 / 列出 / 吊销，以及 /mcp 每次请求时的放行判断。
 *
 * 令牌就是一个 Sanctum token，能力 [mcp:read] 或 [mcp:read, mcp:order]，名字 mcp|<客户起的名字>。
 * 一键授权（McpOAuthServer）发的也是这种令牌，名字是软件名，另在 v2_mcp_oauth_grant 记着是哪个软件。
 * 「多少天没用就失效」用滑动的 expires_at 实现：每次调用把它往后推（一天最多写一次库），
 * Sanctum 自己就会拒绝过期令牌，不用另开定时任务。
 */
class McpTokenService
{
    public const NAME_PREFIX = 'mcp|';

    public const REASON_DISABLED = 'disabled';
    public const REASON_NOT_LISTED = 'not_listed';
    public const REASON_NOT_PAID = 'not_paid';
    public const REASON_ACCOUNT = 'account';

    public function __construct(private readonly McpConfig $config)
    {
    }

    public static function make(): self
    {
        return new self(McpConfig::fromSettings());
    }

    public function config(): McpConfig
    {
        return $this->config;
    }

    /** 这个账号此刻能不能用 AI 助手；不能时返回原因（面板卡片据此显示说明），能用返回 null */
    public function ineligibleReason(User $user): ?string
    {
        if (!$this->config->enabled) {
            return self::REASON_DISABLED;
        }
        // 后台账号不发：令牌只代表客户本人，管理员的账号另有两步验证与接口令牌
        if ($user->banned || $user->is_admin) {
            return self::REASON_ACCOUNT;
        }
        if ($this->config->whitelist !== []) {
            $listed = in_array(mb_strtolower((string) $user->email), $this->config->whitelist, true)
                || in_array((string) $user->id, $this->config->whitelist, true);
            return $listed ? null : self::REASON_NOT_LISTED;
        }
        $paid = Order::where('user_id', $user->id)
            ->whereIn('status', [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
            ->exists();
        return $paid ? null : self::REASON_NOT_PAID;
    }

    /** @return \Illuminate\Support\Collection<int, PersonalAccessToken> */
    public function tokens(User $user)
    {
        return $user->tokens()->where('name', 'like', self::NAME_PREFIX . '%')->orderByDesc('id')->get()
            ->filter(fn (PersonalAccessToken $t) => AuthService::isMcpToken($t))
            ->values();
    }

    /** 面板「AI 助手」页：令牌列表（不含明文），带最后一次调用的 IP；一键授权的标上软件的域名 */
    public function list(User $user): array
    {
        $tokens = $this->tokens($user);
        $lastIps = McpCallLog::whereIn('token_id', $tokens->pluck('id'))
            ->orderByDesc('id')->get(['token_id', 'ip'])
            ->unique('token_id')->pluck('ip', 'token_id');
        $grants = McpOauthGrant::where('user_id', $user->id)->whereIn('token_id', $tokens->pluck('id'))
            ->get(['token_id', 'redirect_host'])->keyBy('token_id');
        return $tokens->map(fn (PersonalAccessToken $t) => [
            'id' => (int) $t->id,
            'name' => Str::after((string) $t->name, self::NAME_PREFIX),
            'can_order' => in_array(AuthService::ABILITY_MCP_ORDER, (array) $t->abilities, true),
            'created_at' => $t->created_at?->getTimestamp(),
            'last_used_at' => $t->last_used_at?->getTimestamp(),
            'last_ip' => $lastIps[$t->id] ?? null,
            'expires_at' => $t->expires_at?->getTimestamp(),
            // oauth = 在授权页点「允许」连上的（ChatGPT 等），token = 手动生成的
            'via' => isset($grants[$t->id]) ? 'oauth' : 'token',
            'app_host' => $grants[$t->id]->redirect_host ?? null,
        ])->all();
    }

    /** 令牌数是否已到上限；$replacing 是马上要换掉的那枚（同一个软件重新授权），不算在内 */
    public function isFull(User $user, ?int $replacing = null): bool
    {
        $count = $this->tokens($user)->filter(fn (PersonalAccessToken $t) => (int) $t->id !== $replacing)->count();
        return $count >= McpConfig::MAX_TOKENS_PER_USER;
    }

    /**
     * 生成令牌。明文只在这一次返回。
     * @return array{id: int, token: string}
     * @throws ApiException
     */
    public function create(User $user, string $name, bool $canOrder): array
    {
        if ($reason = $this->ineligibleReason($user)) {
            throw new ApiException(__('The AI assistant is not available for this account'), 403);
        }
        if ($this->isFull($user)) {
            throw new ApiException(__('You can keep at most :count AI assistant tokens; revoke one first', ['count' => McpConfig::MAX_TOKENS_PER_USER]));
        }
        return $this->issue($user, $name, $canOrder);
    }

    /**
     * 发一枚令牌，不做资格和数量检查（调用方已查过）。一键授权换新令牌也走这里。
     * @return array{id: int, token: string}
     */
    public function issue(User $user, string $name, bool $canOrder): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $name = $name === '' ? 'AI' : Str::limit($name, 40, '');
        $abilities = [AuthService::ABILITY_MCP_READ];
        if ($canOrder) {
            $abilities[] = AuthService::ABILITY_MCP_ORDER;
        }
        $token = $user->createToken(self::NAME_PREFIX . $name, $abilities, now()->addDays($this->config->tokenIdleDays));
        $plain = explode('|', $token->plainTextToken, 2)[1] ?? $token->plainTextToken;
        return ['id' => (int) $token->accessToken->id, 'token' => $plain];
    }

    public function revoke(User $user, int $tokenId): bool
    {
        $token = $this->tokens($user)->firstWhere('id', $tokenId);
        if (!$token) {
            return false;
        }
        // 一键授权的令牌：连同授权一起删，AI 软件手里的 refresh token 也就作废了
        McpOauthGrant::where('user_id', $user->id)->where('token_id', $token->id)->delete();
        return (bool) $token->delete();
    }

    /** 每次调用：把「多少天没用就失效」往后推，一天最多写一次库 */
    public function touch(PersonalAccessToken $token): void
    {
        $target = now()->addDays($this->config->tokenIdleDays);
        if (!$token->expires_at || $token->expires_at->lt($target->copy()->subDay())) {
            $token->forceFill(['expires_at' => $target])->save();
        }
    }

    public static function canOrder(?PersonalAccessToken $token): bool
    {
        return $token !== null && in_array(AuthService::ABILITY_MCP_ORDER, (array) $token->abilities, true);
    }
}
