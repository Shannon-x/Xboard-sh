<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\Auth\AdminTwoFactorService;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 后台「账号安全」页：两步验证状态 / 重新绑定 / 恢复码、登录会话、最近登录记录。
 * 所有改动类操作都要再输一次验证器里的验证码（或恢复码），拿着别人的已登录会话也动不了这些设置。
 */
class SecurityController extends Controller
{
    public function __construct(private AdminTwoFactorService $twoFactor)
    {
    }

    public function status(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $status = $this->twoFactor->status($user);
        $current = $user->currentAccessToken();
        return $this->success(array_merge($status, [
            'session_days' => AuthService::adminSessionDays(),
            'current_session' => $current instanceof PersonalAccessToken ? $this->formatSession($current, $current->id) : null,
        ]));
    }

    /** 重新绑定：先验当前验证码，再发新密钥；旧密钥在 confirm 之前照常有效 */
    public function setup(Request $request)
    {
        $params = $request->validate(['otp' => 'required|string|max:32']);
        /** @var User $user */
        $user = $request->user();
        if (!$this->twoFactor->verifyCode($user, $params['otp'])['ok']) {
            return $this->fail([400, '验证码不正确']);
        }
        return $this->success($this->twoFactor->beginEnrollment($user));
    }

    public function confirm(Request $request)
    {
        $params = $request->validate(['otp' => 'required|string|max:32']);
        /** @var User $user */
        $user = $request->user();
        $codes = $this->twoFactor->confirmEnrollment($user, $params['otp']);
        if ($codes === null) {
            return $this->fail([400, '验证码不正确，请用新扫描的条目重试']);
        }
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_TWO_FACTOR_ENROLLED, $request, ['rebind' => true]);
        return $this->success(['recovery_codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $params = $request->validate(['otp' => 'required|string|max:32']);
        /** @var User $user */
        $user = $request->user();
        if (!$this->twoFactor->verifyCode($user, $params['otp'])['ok']) {
            return $this->fail([400, '验证码不正确']);
        }
        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        if ($codes === null) {
            return $this->fail([400, '尚未绑定验证器']);
        }
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_RECOVERY_CODES_REGENERATED, $request);
        return $this->success(['recovery_codes' => $codes]);
    }

    /**
     * 当前管理员的后台登录会话；用户面板的会话只给计数（一个管理员账号在用户面板、客户端里
     * 登录出来的会话可能成百上千，逐条列出没有意义）。接口令牌在 apiTokens 里单独列。
     */
    public function sessions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $sessions = [];
        $userCount = 0;
        $userLastUsed = null;
        $userLastCreated = null;
        $userLatest = null;
        foreach ($user->tokens()->orderByDesc('id')->cursor() as $token) {
            /** @var PersonalAccessToken $token */
            $kind = AuthService::tokenKind($token);
            if ($kind === AuthService::TOKEN_KIND_ADMIN) {
                $sessions[] = $this->formatSession($token, $currentId);
                continue;
            }
            if ($kind !== AuthService::TOKEN_KIND_USER) {
                continue;
            }
            $userCount++;
            // 汇总行显示最近一个能看出来源的会话（旧 token 的名字是随机串，不带 IP/UA）
            if ($userLatest === null && str_starts_with((string) $token->name, AuthService::TOKEN_KIND_USER . '|')) {
                $userLatest = $token;
            }
            $used = $token->last_used_at?->getTimestamp();
            if ($used !== null && ($userLastUsed === null || $used > $userLastUsed)) {
                $userLastUsed = $used;
            }
            $created = $token->created_at?->getTimestamp();
            if ($created !== null && ($userLastCreated === null || $created > $userLastCreated)) {
                $userLastCreated = $created;
            }
        }
        usort($sessions, fn($a, $b) => ($b['current'] <=> $a['current'])
            ?: (($b['last_used_at'] ?? 0) <=> ($a['last_used_at'] ?? 0))
            ?: ($b['created_at'] <=> $a['created_at']));

        $latest = $userLatest ? $this->formatSession($userLatest, $currentId) : null;
        return $this->success([
            'sessions' => $sessions,
            'user_sessions' => [
                'count' => $userCount,
                'last_created_at' => $userLastCreated,
                'last_used_at' => $userLastUsed,
                'latest_ip' => $latest['ip'] ?? '',
                'latest_user_agent' => $latest['user_agent'] ?? '',
            ],
        ]);
    }

    public function revokeSession(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        /** @var User $user */
        $user = $request->user();
        $token = $user->tokens()->where('id', $params['id'])->first();
        if (!$token || AuthService::tokenKind($token) === AuthService::TOKEN_KIND_API) {
            return $this->fail([404, '会话不存在']);
        }
        $token->delete();
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_SESSION_REVOKED, $request, ['session_id' => (int) $params['id']]);
        return $this->success(true);
    }

    /**
     * 注销除当前以外的全部登录会话（后台 + 用户面板），怀疑泄露时一键踢出。
     * 接口令牌不在其中 —— 它们是给程序用的，单独吊销，免得一键把通知机器人也断了。
     */
    public function revokeOtherSessions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();
        $ids = $this->tokenIds($user, [AuthService::TOKEN_KIND_ADMIN, AuthService::TOKEN_KIND_USER],
            $current instanceof PersonalAccessToken ? $current->id : null);
        $count = $this->deleteTokens($user, $ids);
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_SESSIONS_REVOKED, $request, ['count' => $count]);
        return $this->success(['revoked' => $count]);
    }

    /** 只注销用户面板 / 客户端里的会话（用户面板需要重新登录），后台会话和接口令牌不动 */
    public function revokeUserSessions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->deleteTokens($user, $this->tokenIds($user, [AuthService::TOKEN_KIND_USER]));
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_USER_SESSIONS_REVOKED, $request, ['count' => $count]);
        return $this->success(['revoked' => $count]);
    }

    /** 当前管理员签发的接口令牌 */
    public function apiTokens(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $list = [];
        foreach ($user->tokens()->orderByDesc('id')->cursor() as $token) {
            /** @var PersonalAccessToken $token */
            if (AuthService::tokenKind($token) !== AuthService::TOKEN_KIND_API) {
                continue;
            }
            $list[] = $this->formatApiToken($token);
        }
        return $this->success($list);
    }

    /** 签发接口令牌：要当前验证码；明文只返回这一次 */
    public function createApiToken(Request $request)
    {
        $params = $request->validate([
            'name' => 'required|string|max:40',
            'scope' => 'required|in:read,full',
            'otp' => 'required|string|max:32',
        ], [
            'name.required' => '请填写令牌名称',
            'name.max' => '令牌名称最多 40 个字',
        ]);
        /** @var User $user */
        $user = $request->user();
        if (!$this->twoFactor->verifyCode($user, $params['otp'])['ok']) {
            return $this->fail([400, '验证码不正确']);
        }
        $readOnly = $params['scope'] === 'read';
        $issued = (new AuthService($user))->generateApiToken($params['name'], $readOnly);
        $token = $user->tokens()->where('id', $issued['id'])->first();
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_API_TOKEN_CREATED, $request, [
            'token_id' => $issued['id'],
            'name' => $params['name'],
            'scope' => $params['scope'],
        ]);
        $this->twoFactor->notifyAdmins(
            "🔑 管理员 {$user->email} 新建了接口令牌「{$params['name']}」（" . ($readOnly ? '只读' : '完整权限') . "）\nIP：{$request->ip()}\n时间：" . date('Y-m-d H:i:s')
        );
        return $this->success(array_merge($this->formatApiToken($token), [
            'token' => $issued['token'],
            'auth_data' => $issued['auth_data'],
        ]));
    }

    public function revokeApiToken(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        /** @var User $user */
        $user = $request->user();
        $token = $user->tokens()->where('id', $params['id'])->first();
        if (!$token || AuthService::tokenKind($token) !== AuthService::TOKEN_KIND_API) {
            return $this->fail([404, '令牌不存在']);
        }
        $name = $this->formatApiToken($token)['name'];
        $token->delete();
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_API_TOKEN_REVOKED, $request, [
            'token_id' => (int) $params['id'],
            'name' => $name,
        ]);
        return $this->success(true);
    }

    /** 最近的登录类审计记录（所有管理员），默认 20 条 */
    public function loginLog(Request $request)
    {
        $limit = max(1, min(100, (int) $request->input('limit', 20)));
        $rows = AdminAuditLog::with('admin:id,email')
            ->where('action', 'like', 'auth.%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (AdminAuditLog $row) {
                $data = json_decode((string) $row->request_data, true) ?: [];
                return [
                    'id' => $row->id,
                    'action' => $row->action,
                    'email' => $row->admin?->email ?? ($data['email'] ?? ''),
                    'ip' => $row->ip,
                    'user_agent' => $data['user_agent'] ?? '',
                    'reason' => $data['reason'] ?? '',
                    'recovery_code' => (bool) ($data['recovery_code'] ?? false),
                    'created_at' => $row->created_at,
                ];
            })
            ->values()
            ->all();
        return $this->success($rows);
    }

    private function formatSession(PersonalAccessToken $token, ?int $currentId): array
    {
        $kind = AuthService::tokenKind($token);
        $parts = explode('|', (string) $token->name, 3);
        $named = in_array($parts[0] ?? '', [AuthService::TOKEN_KIND_ADMIN, AuthService::TOKEN_KIND_USER], true) && count($parts) === 3;
        $ip = $named ? ($parts[1] ?? '') : '';
        return [
            'id' => $token->id,
            'kind' => $kind,
            'admin' => $kind === AuthService::TOKEN_KIND_ADMIN,
            'ip' => $ip === '-' ? '' : $ip,
            'user_agent' => $named ? ($parts[2] ?? '') : '',
            'created_at' => $token->created_at?->getTimestamp(),
            'last_used_at' => $token->last_used_at?->getTimestamp(),
            'expires_at' => $token->expires_at?->getTimestamp(),
            'current' => $currentId !== null && $token->id === $currentId,
        ];
    }

    private function formatApiToken(PersonalAccessToken $token): array
    {
        $abilities = is_array($token->abilities) ? $token->abilities : [];
        return [
            'id' => $token->id,
            'name' => (string) preg_replace('/^api\|/', '', (string) $token->name),
            'scope' => in_array(AuthService::ABILITY_ADMIN, $abilities, true) ? 'full' : 'read',
            'created_at' => $token->created_at?->getTimestamp(),
            'last_used_at' => $token->last_used_at?->getTimestamp(),
        ];
    }

    /** @param string[] $kinds */
    private function tokenIds(User $user, array $kinds, ?int $exceptId = null): array
    {
        $ids = [];
        foreach ($user->tokens()->select(['id', 'abilities'])->cursor() as $token) {
            /** @var PersonalAccessToken $token */
            if ($exceptId !== null && $token->id === $exceptId) {
                continue;
            }
            if (in_array(AuthService::tokenKind($token), $kinds, true)) {
                $ids[] = $token->id;
            }
        }
        return $ids;
    }

    private function deleteTokens(User $user, array $ids): int
    {
        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $count += $user->tokens()->whereIn('id', $chunk)->delete();
        }
        return $count;
    }
}
