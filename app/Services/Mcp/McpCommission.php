<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\CommissionWithdrawal;
use App\Models\User;
use App\Models\UserPayoutProfile;
use App\Services\Commission\InviteeStats;
use App\Services\Commission\WithdrawalConfig;
use App\Services\CommissionWithdrawalService;
use App\Services\EmailVerification;
use App\Services\Mcp\McpPresenter as P;

/**
 * 客户 AI 助手的佣金提现：给 AI 看的提现状态，以及提现前的检查。
 *
 * AI 只能提到客户自己在面板填过的收款地址（UserPayoutProfile），不能填新地址：
 * 令牌万一外泄，拿到令牌的人也只能把佣金打回客户本人的地址。第一次提现、换地址、
 * 上传收款二维码都留在面板。提现单本身走 CommissionWithdrawalService，与面板同一条流程。
 */
final class McpCommission
{
    /** 附在提现工单首条消息末尾，管理员一眼看出这笔是 AI 代提的 */
    public const AI_NOTE = '（由客户的 AI 助手代为提交，收款地址沿用客户在面板填写的地址）';

    public static function withdrawal(User $user, ?WithdrawalConfig $config = null): array
    {
        $config ??= WithdrawalConfig::fromSettings();
        $profile = UserPayoutProfile::where('user_id', $user->id)->first();
        $chain = $profile ? $config->findChain((string) $profile->chain_code) : null;
        $rows = CommissionWithdrawal::where('user_id', $user->id)->orderByDesc('id')->limit(5)->get();
        $pending = $rows->first(fn (CommissionWithdrawal $w) => $w->isPending())
            ?? CommissionWithdrawal::where('user_id', $user->id)->where('status', CommissionWithdrawal::STATUS_PENDING)->first();

        return [
            'enabled' => $config->enable,
            'min_yuan' => P::yuan($config->minCents),
            'max_yuan' => $config->maxCents > 0 ? P::yuan($config->maxCents) : null,
            'paid_invitees_needed' => $config->minInvitees,
            'paid_invitees' => InviteeStats::paidInviteeCount((int) $user->id),
            'payout_address' => $profile && $chain ? [
                'network' => WithdrawalConfig::chainLabel($chain),
                'address' => self::mask((string) $profile->address),
                'has_qr_code' => (bool) $profile->qr_attachment_id,
            ] : null,
            'payout_address_note' => $profile && $chain ? null
                : 'AI 只能提到客户在面板填过的收款地址。第一次提现或要换地址，请客户到面板邀请页提现一次，之后就能让 AI 代办。',
            'usdt_rate' => $config->usdtRate(),
            'pending' => $pending ? self::row($pending) : null,
            'history' => $rows->map(fn (CommissionWithdrawal $w) => self::row($w))->values()->all(),
        ];
    }

    /**
     * 提现前检查。通过时给出要用的链、地址和 USDT 估算；不通过时抛出给客户看的一句话。
     * 申请时 CommissionWithdrawalService::apply 还会在锁内再查一遍余额与待处理单。
     *
     * @return array{chain: array, address: string, quote: array}
     * @throws ApiException
     */
    public static function prepare(User $user, int $amountCents, WithdrawalConfig $config): array
    {
        if (!$config->enable) {
            throw new ApiException('本站暂未开放佣金提现。');
        }
        EmailVerification::assertAllowed(User::findOrFail($user->id));
        $profile = UserPayoutProfile::where('user_id', $user->id)->first();
        if (!$profile) {
            throw new ApiException('AI 只能提到你在面板填过的收款地址。第一次提现（或要换收款地址）请到面板邀请页操作一次，之后就可以让 AI 代办。');
        }
        $chain = $config->findChain((string) $profile->chain_code);
        if (!$chain || !WithdrawalConfig::addressMatches($chain, (string) $profile->address)) {
            throw new ApiException('你上次用的收款网络已停用或地址不再有效，请到面板邀请页换一个收款地址。');
        }
        if ($config->requireQrcode && !$profile->qr_attachment_id) {
            throw new ApiException('本站提现需要收款二维码，AI 没法替你上传。请到面板邀请页提现一次并上传二维码，之后 AI 就能沿用。');
        }
        if ($amountCents < $config->minCents) {
            throw new ApiException('单次最少提现 ¥' . P::yuan($config->minCents) . '。');
        }
        if ($config->maxCents > 0 && $amountCents > $config->maxCents) {
            throw new ApiException('单次最多提现 ¥' . P::yuan($config->maxCents) . '。');
        }
        $available = (int) User::where('id', $user->id)->value('commission_balance');
        if ($amountCents > $available) {
            throw new ApiException('可提现佣金只有 ¥' . P::yuan($available) . '。');
        }
        (new CommissionWithdrawalService($config))->assertInviteeThreshold($user);
        if (CommissionWithdrawal::where('user_id', $user->id)->where('status', CommissionWithdrawal::STATUS_PENDING)->exists()) {
            throw new ApiException('你已有一笔提现在处理中，等它完成，或先取消（cancel_withdrawal）再重新申请。');
        }

        return [
            'chain' => $chain,
            'address' => (string) $profile->address,
            'quote' => $config->quote($amountCents, $chain),
        ];
    }

    public static function row(CommissionWithdrawal $w): array
    {
        return [
            'reference' => $w->reference(),
            'amount_yuan' => P::yuan((int) $w->amount),
            'network' => $w->network ? "{$w->chain_name} · {$w->network}" : (string) $w->chain_name,
            'address' => self::mask((string) $w->address),
            'status' => CommissionWithdrawal::$statusMap[$w->status] ?? (string) $w->status,
            'applied_at' => P::time($w->created_at ? (int) $w->getRawOriginal('created_at') : null),
            'finished_at' => P::time($w->settled_at ? (int) $w->settled_at : null),
            'estimated_usdt' => $w->usdt_amount,
            'paid_usdt' => $w->paid_usdt,
            'reject_reason' => $w->reject_reason,
        ];
    }

    /** 收款地址只给头尾：对话记录会留在第三方 AI 那里，客户核对尾号就够了 */
    public static function mask(string $address): string
    {
        $len = mb_strlen($address);
        return $len <= 12 ? $address : mb_substr($address, 0, 6) . '…' . mb_substr($address, -4);
    }
}
