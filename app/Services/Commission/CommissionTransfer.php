<?php

namespace App\Services\Commission;

use App\Exceptions\ApiException;
use App\Models\BalanceLog;
use App\Models\User;
use App\Services\BalanceLedger;
use App\Services\CommissionWithdrawalService;
use App\Services\EmailVerification;
use Illuminate\Support\Facades\DB;

/**
 * 佣金转余额：面板邀请页（UserController::transfer）与客户 AI 助手共用。
 */
final class CommissionTransfer
{
    /**
     * 划转前的门槛。佣金转余额与佣金提现同属「拿佣金」，邮箱软验证受限时一起挡（下单付款不受影响）；
     * 后台开启「划转同样受人数门槛」时，划转与提现一样要求不同付费被邀请人 ≥ N：
     * 否则自邀者不提现、直接把佣金划成余额抵下一单，提现门槛就被绕过了。
     *
     * @throws ApiException
     */
    public static function assertAllowed(User $user): void
    {
        EmailVerification::assertAllowed(User::findOrFail($user->id));
        $config = WithdrawalConfig::fromSettings();
        if ($config->transferRequireInvitees) {
            (new CommissionWithdrawalService($config))->assertInviteeThreshold($user);
        }
    }

    /**
     * 把 $amountCents 分佣金挪进余额并记账。不做 assertAllowed，调用方先查。
     *
     * @throws ApiException
     */
    public static function move(int $userId, int $amountCents): User
    {
        return DB::transaction(function () use ($userId, $amountCents) {
            $user = User::lockForUpdate()->find($userId);
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }
            if ($amountCents > $user->commission_balance) {
                throw new ApiException(__('Insufficient commission balance'));
            }
            $user->commission_balance -= $amountCents;
            $user->balance += $amountCents;
            if (!$user->save()) {
                throw new ApiException(__('Transfer failed'));
            }
            BalanceLedger::record($user, BalanceLog::TYPE_COMMISSION_TRANSFER, $amountCents, [
                'ref_type' => 'commission', 'remark' => '佣金划转到余额',
            ]);
            return $user;
        });
    }
}
