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

    /** 当前管理员的全部会话（含用户面板的普通会话，用 admin 字段区分） */
    public function sessions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;
        $list = $user->tokens()->orderByDesc('last_used_at')->orderByDesc('created_at')->get()
            ->map(fn(PersonalAccessToken $token) => $this->formatSession($token, $currentId))
            ->values()
            ->all();
        return $this->success($list);
    }

    public function revokeSession(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer']);
        /** @var User $user */
        $user = $request->user();
        $token = $user->tokens()->where('id', $params['id'])->first();
        if (!$token) {
            return $this->fail([404, '会话不存在']);
        }
        $token->delete();
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_SESSION_REVOKED, $request, ['session_id' => (int) $params['id']]);
        return $this->success(true);
    }

    /** 注销除当前以外的全部会话（怀疑泄露时一键踢出） */
    public function revokeOtherSessions(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();
        $query = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $query->where('id', '!=', $current->id);
        }
        $count = $query->delete();
        $this->twoFactor->audit($user, AdminTwoFactorService::ACTION_SESSIONS_REVOKED, $request, ['count' => $count]);
        return $this->success(['revoked' => $count]);
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
        $parts = explode('|', (string) $token->name, 3);
        $isAdminName = ($parts[0] ?? '') === 'admin';
        return [
            'id' => $token->id,
            'admin' => AuthService::tokenGrantsAdmin($token),
            'ip' => $isAdminName ? ($parts[1] ?? '') : '',
            'user_agent' => $isAdminName ? ($parts[2] ?? '') : '',
            'created_at' => $token->created_at?->getTimestamp(),
            'last_used_at' => $token->last_used_at?->getTimestamp(),
            'expires_at' => $token->expires_at?->getTimestamp(),
            'current' => $currentId !== null && $token->id === $currentId,
        ];
    }
}
