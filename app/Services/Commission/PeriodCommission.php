<?php

namespace App\Services\Commission;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Jobs\SendTelegramJob;
use App\Models\CommissionPeriodApplication;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 大推广者的周期返佣（申请制）：
 *   1. 后台开关 commission_period_auto_enable 打开、有效付费被邀请人 ≥ commission_period_auto_min_invitees 时，用户可以在邀请页申请；
 *   2. 站长在后台审核，通过才开通（周期返佣 = 被邀请人每次付费都返，含续费），驳回要写原因；
 *   3. 已开通的用户有效付费被邀请人一旦低于门槛，自动取消资格（发佣 / 打开邀请页 / 每日巡检三处都会检查），
 *      之后再达标需要重新申请。
 *
 * 「有效付费被邀请人」口径见 InviteeStats::activePaidInviteeCount()。设置键沿用上一版「自动开启」的键名，
 * 只是语义从「达标自动开」改成「达标可申请」，站长已经填好的值不用重填。
 * 管理员在用户编辑里单设了返佣方式（周期 / 一次性）时以单设为准，申请制不参与（见 CommissionPolicy）。
 *
 * 发给用户的通知（通过 / 驳回 / 取消资格）不带任何编号，也不受通知偏好限制：它们是对用户自己申请的答复。
 */
final class PeriodCommission
{
    /** @return array{enable:bool, min_invitees:int} */
    public static function settings(): array
    {
        return CommissionPolicy::periodAuto();
    }

    public static function latest(int $userId): ?CommissionPeriodApplication
    {
        return CommissionPeriodApplication::where('user_id', $userId)->orderByDesc('id')->first();
    }

    /**
     * 发佣与展示共用：申请已通过、开关开着、有效人数仍达标 → true。
     * 已通过但人数掉到门槛以下 → 当场取消资格（落库 + 通知）并返回 false。
     */
    public static function grants(User $inviter, int $activePaidInvitees): bool
    {
        $settings = self::settings();
        if (!$settings['enable']) {
            return false;
        }
        $application = self::latest((int) $inviter->id);
        if (!$application || (int) $application->status !== CommissionPeriodApplication::STATUS_APPROVED) {
            return false;
        }
        if ($activePaidInvitees >= $settings['min_invitees']) {
            return true;
        }
        self::revoke($application, $activePaidInvitees, $settings['min_invitees']);
        return false;
    }

    /**
     * 邀请页用的申请状态。status：none（从没申请过）/ pending / approved / rejected / revoked。
     *
     * @return array{status:string, reason:?string, applied_at:?int, decided_at:?int, revoked_at:?int, can_apply:bool}
     */
    public static function state(User $user, int $activePaidInvitees): array
    {
        $settings = self::settings();
        $application = self::latest((int) $user->id);
        $status = $application ? $application->statusKey() : 'none';
        $canApply = $settings['enable']
            && (int) $user->commission_type === User::COMMISSION_TYPE_SYSTEM
            && $activePaidInvitees >= $settings['min_invitees']
            && !in_array($status, ['pending', 'approved'], true);

        return [
            'status' => $status,
            'reason' => $status === 'rejected' ? $application->reason : null,
            'applied_at' => $application ? (int) $application->created_at : null,
            'decided_at' => $application && $application->decided_at ? (int) $application->decided_at : null,
            'revoked_at' => $application && $application->revoked_at ? (int) $application->revoked_at : null,
            'can_apply' => $canApply,
        ];
    }

    /** @throws ApiException */
    public function apply(User $user): CommissionPeriodApplication
    {
        $settings = self::settings();
        if (!$settings['enable']) {
            throw new ApiException(__('Periodic commission applications are not open'));
        }
        if ((int) $user->commission_type !== User::COMMISSION_TYPE_SYSTEM) {
            throw new ApiException(__('Your commission mode is set by the administrator, no application is needed'));
        }

        $application = DB::transaction(function () use ($user, $settings) {
            // 锁住用户行，防止连点生成两条待审核
            User::where('id', $user->id)->lockForUpdate()->first();
            $latest = self::latest((int) $user->id);
            if ($latest && (int) $latest->status === CommissionPeriodApplication::STATUS_PENDING) {
                throw new ApiException(__('Your application is under review'));
            }
            if ($latest && (int) $latest->status === CommissionPeriodApplication::STATUS_APPROVED) {
                throw new ApiException(__('Periodic commission is already enabled'));
            }
            $active = InviteeStats::activePaidInviteeCount((int) $user->id);
            if ($active < $settings['min_invitees']) {
                throw new ApiException(__('You can apply once you have :min active paying invitees, you currently have :count', [
                    'min' => $settings['min_invitees'],
                    'count' => $active,
                ]));
            }
            return CommissionPeriodApplication::create([
                'user_id' => $user->id,
                'status' => CommissionPeriodApplication::STATUS_PENDING,
                'active_count' => $active,
                'min_invitees' => $settings['min_invitees'],
            ]);
        });

        $this->notifyAdmins(sprintf(
            "📈 周期返佣申请\n邮箱：%s\n有效付费被邀请人：%d（门槛 %d）\n请到后台「周期返佣申请」审核",
            $user->email,
            $application->active_count,
            $application->min_invitees
        ));
        HookManager::call('commission.period.apply.after', $application);

        return $application;
    }

    /** @throws ApiException */
    public function approve(int $id, User $admin): CommissionPeriodApplication
    {
        $settings = self::settings();
        $application = DB::transaction(function () use ($id, $admin, $settings) {
            /** @var CommissionPeriodApplication|null $application */
            $application = CommissionPeriodApplication::where('id', $id)->lockForUpdate()->first();
            if (!$application) {
                throw new ApiException('申请不存在');
            }
            if ((int) $application->status !== CommissionPeriodApplication::STATUS_PENDING) {
                throw new ApiException('这条申请已经处理过了');
            }
            // 通过那一刻再数一次：审核期间人数可能已经掉下去了，通过了也会被立刻取消
            $active = InviteeStats::activePaidInviteeCount((int) $application->user_id);
            if ($active < $settings['min_invitees']) {
                throw new ApiException(sprintf('该用户当前有效付费被邀请人 %d 位，低于门槛 %d 位，不能通过；可以驳回', $active, $settings['min_invitees']));
            }
            $application->status = CommissionPeriodApplication::STATUS_APPROVED;
            $application->admin_id = $admin->id;
            $application->decided_at = time();
            $application->save();
            return $application;
        });

        $user = User::find($application->user_id);
        if ($user) {
            $this->notifyUser($user, 'approved', ['min' => $settings['min_invitees']]);
        }
        return $application;
    }

    /** @throws ApiException */
    public function reject(int $id, User $admin, string $reason): CommissionPeriodApplication
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException('请填写驳回原因');
        }
        $application = DB::transaction(function () use ($id, $admin, $reason) {
            /** @var CommissionPeriodApplication|null $application */
            $application = CommissionPeriodApplication::where('id', $id)->lockForUpdate()->first();
            if (!$application) {
                throw new ApiException('申请不存在');
            }
            if ((int) $application->status !== CommissionPeriodApplication::STATUS_PENDING) {
                throw new ApiException('这条申请已经处理过了');
            }
            $application->status = CommissionPeriodApplication::STATUS_REJECTED;
            $application->admin_id = $admin->id;
            $application->reason = mb_substr($reason, 0, 255);
            $application->decided_at = time();
            $application->save();
            return $application;
        });

        $user = User::find($application->user_id);
        if ($user) {
            $this->notifyUser($user, 'rejected', ['reason' => $application->reason]);
        }
        return $application;
    }

    /**
     * 每日巡检：已开通的用户逐个重数有效付费被邀请人，低于门槛的取消资格。
     * 开关关着时不巡检（关着时本来就不生效，重新打开后仍达标的照常生效）。
     *
     * @return int 本次取消的人数
     */
    public function sweep(): int
    {
        $settings = self::settings();
        if (!$settings['enable']) {
            return 0;
        }
        $revoked = 0;
        CommissionPeriodApplication::where('status', CommissionPeriodApplication::STATUS_APPROVED)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($settings, &$revoked) {
                foreach ($rows as $application) {
                    // 同一用户只看最新一行（理论上已通过的只会有一行）
                    $latest = self::latest((int) $application->user_id);
                    if (!$latest || (int) $latest->id !== (int) $application->id) {
                        continue;
                    }
                    $active = InviteeStats::activePaidInviteeCount((int) $application->user_id);
                    if ($active < $settings['min_invitees'] && self::revoke($application, $active, $settings['min_invitees'])) {
                        $revoked++;
                    }
                }
            });
        return $revoked;
    }

    /**
     * 取消资格。条件更新保证并发下只取消（和通知）一次；失败只记日志 ——
     * 这条路径会从下单（OrderService::setInvite）里走到，绝不能让它影响下单。
     */
    private static function revoke(CommissionPeriodApplication $application, int $active, int $min): bool
    {
        try {
            $affected = CommissionPeriodApplication::where('id', $application->id)
                ->where('status', CommissionPeriodApplication::STATUS_APPROVED)
                ->update([
                    'status' => CommissionPeriodApplication::STATUS_REVOKED,
                    'revoked_at' => time(),
                    'revoked_count' => $active,
                    'updated_at' => time(),
                ]);
            if ($affected !== 1) {
                return false;
            }
            $user = User::find($application->user_id);
            if ($user) {
                (new self())->notifyUser($user, 'revoked', ['count' => $active, 'min' => $min]);
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[commission] 周期返佣取消资格失败', ['user_id' => $application->user_id, 'msg' => $e->getMessage()]);
            return false;
        }
    }

    /** 审核结果 / 取消资格：邮件（通用 notify 模板）+ 已绑定 Telegram 的再推一条。不带编号。 */
    private function notifyUser(User $user, string $kind, array $vars): void
    {
        try {
            $appName = (string) admin_setting('app_name', 'XBoard');
            $appUrl = rtrim((string) admin_setting('app_url', ''), '/');
            $inviteUrl = $appUrl . '/invite';
            [$subject, $body] = match ($kind) {
                'approved' => [
                    __('Periodic commission enabled'),
                    __('Your periodic commission application has been approved. From now on, every payment by the users you invited, renewals included, earns you commission. Keep at least :min active paying invitees; below that the qualification is cancelled automatically and you can apply again once you reach it.', $vars),
                ],
                'rejected' => [
                    __('Periodic commission application not approved'),
                    __('Sorry, your periodic commission application was not approved: :reason. You can apply again on the invite page.', $vars),
                ],
                default => [
                    __('Periodic commission qualification cancelled'),
                    __('You now have :count active paying invitees, below the threshold of :min, so your periodic commission qualification has been cancelled and commission is back to the default rule. Once you reach :min again, you can reapply on the invite page.', $vars),
                ],
            };
            $content = $body . "\n\n" . __('View commissions') . '：' . $inviteUrl;

            if ($user->email && $user->mail_suppressed_at === null) {
                SendEmailJob::dispatch([
                    'email' => $user->email,
                    'subject' => $subject . ' - ' . $appName,
                    'template_name' => 'notify',
                    'template_value' => [
                        'name' => $appName,
                        'url' => $appUrl,
                        'content' => $content,
                    ],
                ])->afterCommit();
            }
            if ($user->telegram_id) {
                SendTelegramJob::dispatch((int) $user->telegram_id, $subject . "\n" . $content, '')->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('[commission] 周期返佣通知发送失败', ['user_id' => $user->id, 'kind' => $kind, 'msg' => $e->getMessage()]);
        }
    }

    /** 新申请推给绑定了 Telegram 的管理员；没开机器人就静默 */
    private function notifyAdmins(string $text): void
    {
        if (!(int) admin_setting('telegram_bot_enable', 0)) {
            return;
        }
        try {
            app(TelegramService::class)->sendMessageWithAdmin($text, false, '');
        } catch (\Throwable $e) {
            Log::warning('[commission] 周期返佣申请推送管理员失败: ' . $e->getMessage());
        }
    }
}
