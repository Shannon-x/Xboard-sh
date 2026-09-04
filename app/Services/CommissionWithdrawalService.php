<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\CommissionWithdrawal;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserPayoutProfile;
use App\Services\Commission\WithdrawalConfig;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * 佣金提现工作流。
 *
 * 申请 → （申请时即扣除冻结佣金，自动开一张系统工单，附二维码）→ 后台结算 / 驳回 / 用户取消
 *   · 结算：记录 txid / 实付 USDT，系统以管理员身份回复工单并关闭，发邮件感谢
 *   · 驳回 / 取消：退回冻结的佣金，回复工单说明原因并关闭，发邮件
 */
class CommissionWithdrawalService
{
    private WithdrawalConfig $config;

    public function __construct(?WithdrawalConfig $config = null)
    {
        $this->config = $config ?? WithdrawalConfig::fromSettings();
    }

    public function config(): WithdrawalConfig
    {
        return $this->config;
    }

    /**
     * 用户提交申请。
     *
     * @param array $chain WithdrawalConfig 里的链定义（老接口可传合成的 preset=none 链）
     * @param int[] $attachmentIds 已上传的二维码等附件（先经 TicketAttachmentService::validatePendingIds）
     * @param bool $reuseSavedQr 没有新上传时，沿用上次保存的二维码（复制一份绑定到新工单）
     * @throws ApiException
     */
    public function apply(User $user, int $amountCents, array $chain, string $address, array $attachmentIds = [], bool $reuseSavedQr = false): CommissionWithdrawal
    {
        if (!$this->config->enable) {
            throw new ApiException(__('Unsupported withdrawal'));
        }
        $address = trim($address);
        if (!WithdrawalConfig::addressMatches($chain, $address)) {
            throw new ApiException(__('The withdrawal address format is invalid'));
        }
        if ($amountCents <= 0) {
            throw new ApiException(__('Invalid parameter'));
        }
        if ($amountCents < $this->config->minCents) {
            throw new ApiException(__('The current required minimum withdrawal commission is :limit', ['limit' => $this->config->minCents / 100]));
        }
        if ($this->config->maxCents > 0 && $amountCents > $this->config->maxCents) {
            throw new ApiException(__('The maximum amount per withdrawal is :limit', ['limit' => $this->config->maxCents / 100]));
        }

        $profile = UserPayoutProfile::where('user_id', $user->id)->first();
        if (!$attachmentIds && $reuseSavedQr && $profile && $profile->qr_attachment_id) {
            $savedQr = TicketAttachment::find($profile->qr_attachment_id);
            if ($savedQr && $savedQr->user_id === $user->id) {
                $attachmentIds = [(new TicketAttachmentService())->duplicate($savedQr, $user)->id];
            }
        }
        if ($this->config->requireQrcode && empty($attachmentIds)) {
            throw new ApiException(__('Please upload the QR code of your receiving address'));
        }

        $withdrawal = null;
        $ticket = null;
        try {
            DB::beginTransaction();

            /** @var User $locked */
            $locked = User::where('id', $user->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new ApiException(__('The user does not exist'));
            }
            if (CommissionWithdrawal::where('user_id', $locked->id)->where('status', CommissionWithdrawal::STATUS_PENDING)->exists()) {
                throw new ApiException(__('You already have a pending withdrawal, please wait for it to be processed'));
            }
            if ($amountCents > (int) $locked->commission_balance) {
                throw new ApiException(__('Insufficient commission balance'));
            }

            // 申请即冻结：从余额扣走，钱记在提现单上；驳回 / 取消时原路退回
            $locked->commission_balance = (int) $locked->commission_balance - $amountCents;
            if (!$locked->save()) {
                throw new ApiException(__('Save failed'));
            }

            // 申请时的行情只是快照：真正到账多少以结算那一刻的汇率为准（见 settle()）
            $quote = $this->config->quote($amountCents, $chain);
            $withdrawal = CommissionWithdrawal::create([
                'user_id' => $locked->id,
                'amount' => $amountCents,
                'currency' => $this->config->currency,
                'chain_code' => $chain['code'],
                'chain_name' => $chain['name'],
                'network' => $chain['network'] ?? '',
                'address' => $address,
                'usdt_rate' => $quote['rate'] !== null ? WithdrawalConfig::money($quote['rate']) : null,
                'usdt_fee' => $quote['fee'],
                'usdt_amount' => $quote['net'],
                'rate_source' => $this->config->rateSnapshot()['source'],
                'status' => CommissionWithdrawal::STATUS_PENDING,
            ]);

            $ticket = Ticket::create([
                'user_id' => $locked->id,
                'subject' => $this->ticketSubject($withdrawal),
                'level' => 2,
            ]);
            $message = TicketMessage::create([
                'user_id' => $locked->id,
                'ticket_id' => $ticket->id,
                'message' => $this->applyMessage($withdrawal),
            ]);
            if ($attachmentIds) {
                (new TicketAttachmentService())->attachToMessage($attachmentIds, $message, $locked->id);
            }
            $withdrawal->ticket_id = $ticket->id;
            $withdrawal->save();

            // 记住收款信息：下次申请自动预填。二维码只在本次有新图（或沿用）时更新；
            // 换了地址又没传新图就清掉旧图 —— 旧地址的二维码留着只会误导管理员。
            $qrId = $attachmentIds
                ? TicketAttachment::whereIn('id', $attachmentIds)->where('is_image', true)->orderBy('id')->value('id')
                : null;
            if ($qrId === null && $profile && $profile->address === $address && $profile->chain_code === $chain['code']) {
                $qrId = $profile->qr_attachment_id;
            }
            UserPayoutProfile::updateOrCreate(
                ['user_id' => $locked->id],
                ['chain_code' => $chain['code'], 'address' => $address, 'qr_attachment_id' => $qrId]
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 工单通知（Telegram 插件等）走既有钩子，管理员立刻能看到附件里的二维码
        HookManager::call('ticket.create.after', $ticket);
        HookManager::call('commission.withdraw.apply.after', $withdrawal);

        return $withdrawal;
    }

    /**
     * 用户取消待处理的申请，佣金退回。
     *
     * @throws ApiException
     */
    public function cancel(User $user, int $withdrawalId): CommissionWithdrawal
    {
        return $this->refundAndClose(
            $withdrawalId,
            CommissionWithdrawal::STATUS_CANCELLED,
            static fn(CommissionWithdrawal $w) => $w->user_id === $user->id,
            null,
            null,
            "用户已取消本次提现申请，冻结的佣金 {$this->config->currencySymbol}%s 已退回账户。",
            $user->id,
            false
        );
    }

    /**
     * 管理员驳回：退回佣金，回复工单说明原因，邮件通知。
     *
     * @throws ApiException
     */
    public function reject(int $withdrawalId, User $admin, string $reason): CommissionWithdrawal
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiException('请填写驳回原因');
        }
        return $this->refundAndClose(
            $withdrawalId,
            CommissionWithdrawal::STATUS_REJECTED,
            null,
            $admin->id,
            $reason,
            "很抱歉，本次提现申请未能通过：{$reason}\n冻结的佣金 {$this->config->currencySymbol}%s 已退回你的账户，可修改信息后重新申请。",
            $admin->id,
            true
        );
    }

    /**
     * 管理员确认已打款：写入交易信息、回复并关闭工单、邮件感谢。余额在申请时已扣，这里不再动。
     *
     * @throws ApiException
     */
    public function settle(int $withdrawalId, User $admin, ?string $txid = null, ?string $paidUsdt = null, ?string $remark = null): CommissionWithdrawal
    {
        try {
            DB::beginTransaction();
            /** @var CommissionWithdrawal|null $withdrawal */
            $withdrawal = CommissionWithdrawal::where('id', $withdrawalId)->lockForUpdate()->first();
            if (!$withdrawal) {
                throw new ApiException('提现申请不存在');
            }
            if (!$withdrawal->isPending()) {
                throw new ApiException('该申请已处理过（' . (CommissionWithdrawal::$statusMap[$withdrawal->status] ?? $withdrawal->status) . '）');
            }

            // 打款那一刻重新按实时汇率折算：申请可能是几小时前提的，行情早就变了。
            // 管理员手填了实付金额就以手填为准（比如他在交易所看到的真实扣费）。
            $settleQuote = $this->config->quote((int) $withdrawal->amount, $this->config->findChain($withdrawal->chain_code));
            $withdrawal->status = CommissionWithdrawal::STATUS_COMPLETED;
            $withdrawal->admin_id = $admin->id;
            $withdrawal->txid = $txid !== null && trim($txid) !== '' ? mb_substr(trim($txid), 0, 255) : null;
            $withdrawal->paid_usdt = $paidUsdt !== null && is_numeric($paidUsdt)
                ? WithdrawalConfig::money((float) $paidUsdt)
                : $settleQuote['net'];
            $withdrawal->settle_rate = $settleQuote['rate'] !== null ? WithdrawalConfig::money($settleQuote['rate']) : null;
            $withdrawal->remark = $remark !== null && trim($remark) !== '' ? mb_substr(trim($remark), 0, 500) : null;
            $withdrawal->settled_at = time();
            $withdrawal->save();

            $reply = $this->settleMessage($withdrawal);
            $this->replyTicketAsAdmin($withdrawal, $admin->id, $reply, true);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->notifyUser($withdrawal, '你的佣金提现已完成', $reply, 'withdrawCompleted');
        HookManager::call('commission.withdraw.settle.after', $withdrawal);

        return $withdrawal;
    }

    /**
     * 上次保存的收款信息（供前端预填），二维码附件若已被清理则视为无。
     */
    public function savedProfile(User $user): ?array
    {
        $profile = UserPayoutProfile::where('user_id', $user->id)->first();
        if (!$profile) {
            return null;
        }
        $qr = null;
        if ($profile->qr_attachment_id) {
            $attachment = TicketAttachment::find($profile->qr_attachment_id);
            if ($attachment && $attachment->user_id === $user->id && $attachment->ticket_message_id !== null) {
                $qr = [
                    'attachment_id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'path' => $attachment->downloadPath(),
                    'url' => $attachment->downloadUrl(),
                ];
            }
        }
        return [
            'chain_code' => $profile->chain_code,
            'address' => $profile->address,
            'qr' => $qr,
        ];
    }

    public function clearSavedProfile(User $user): void
    {
        UserPayoutProfile::where('user_id', $user->id)->delete();
    }

    /**
     * 驳回与取消共用：退回佣金 → 改状态 → 回复并关闭工单 → （驳回时）邮件通知。
     *
     * @param callable|null $guard 额外的归属校验
     * @throws ApiException
     */
    private function refundAndClose(
        int $withdrawalId,
        int $status,
        ?callable $guard,
        ?int $adminId,
        ?string $reason,
        string $replyTemplate,
        int $replyAuthorId,
        bool $sendEmail
    ): CommissionWithdrawal {
        try {
            DB::beginTransaction();
            /** @var CommissionWithdrawal|null $withdrawal */
            $withdrawal = CommissionWithdrawal::where('id', $withdrawalId)->lockForUpdate()->first();
            if (!$withdrawal || ($guard && !$guard($withdrawal))) {
                throw new ApiException(__('The withdrawal request does not exist'));
            }
            if (!$withdrawal->isPending()) {
                throw new ApiException(__('The withdrawal request has already been processed'));
            }

            /** @var User|null $user */
            $user = User::where('id', $withdrawal->user_id)->lockForUpdate()->first();
            if ($user) {
                $user->commission_balance = (int) $user->commission_balance + (int) $withdrawal->amount;
                $user->save();
            }

            $withdrawal->status = $status;
            $withdrawal->admin_id = $adminId;
            $withdrawal->reject_reason = $reason ? mb_substr($reason, 0, 255) : null;
            $withdrawal->settled_at = time();
            $withdrawal->save();

            $reply = sprintf($replyTemplate, number_format($withdrawal->amount / 100, 2, '.', ''));
            $this->replyTicketAsAdmin($withdrawal, $replyAuthorId, $reply, true);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if ($sendEmail) {
            $this->notifyUser($withdrawal, '你的佣金提现申请未通过', $reply, 'withdrawRejected');
        }
        HookManager::call('commission.withdraw.close.after', $withdrawal);

        return $withdrawal;
    }

    private function ticketSubject(CommissionWithdrawal $w): string
    {
        $chain = $w->network ? "{$w->chain_name} · {$w->network}" : $w->chain_name;
        return sprintf('[提现申请] #%d %s%s → %s', $w->id, $this->config->currencySymbol, number_format($w->amount / 100, 2, '.', ''), $chain);
    }

    private function applyMessage(CommissionWithdrawal $w): string
    {
        $lines = [
            "提现申请 #{$w->id}",
            "金额：{$this->config->currencySymbol}" . number_format($w->amount / 100, 2, '.', ''),
        ];
        if ($w->usdt_amount !== null) {
            $lines[] = "预计到账：≈ {$w->usdt_amount} USDT" . $this->feeSuffix($w) . "（汇率 {$w->usdt_rate}）";
        }
        $lines[] = '链：' . ($w->network ? "{$w->chain_name} · {$w->network}" : $w->chain_name);
        $lines[] = "地址：{$w->address}";
        $lines[] = '';
        $lines[] = '本工单由系统发出，佣金已冻结；管理员打款后会在此回复并关闭工单。';
        $lines[] = '最终到账金额以打款时的实时汇率与通道费为准。';
        return implode("\n", $lines);
    }

    private function settleMessage(CommissionWithdrawal $w): string
    {
        $lines = [
            "✅ 提现 #{$w->id} 已完成",
            "金额：{$this->config->currencySymbol}" . number_format($w->amount / 100, 2, '.', ''),
        ];
        if ($w->paid_usdt !== null) {
            $lines[] = "实付：{$w->paid_usdt} USDT" . $this->feeSuffix($w)
                . ($w->settle_rate !== null ? "（打款汇率 {$w->settle_rate}）" : '');
        } elseif ($w->usdt_amount !== null) {
            $lines[] = "预计到账：≈ {$w->usdt_amount} USDT" . $this->feeSuffix($w);
        }
        $lines[] = '链：' . ($w->network ? "{$w->chain_name} · {$w->network}" : $w->chain_name);
        $lines[] = "地址：{$w->address}";
        if ($w->txid) {
            $lines[] = "交易哈希：{$w->txid}";
        }
        $lines[] = '';
        $lines[] = $this->config->thanks;
        return implode("\n", $lines);
    }

    /** 有通道费时补一句「已扣通道费 x USDT」，没有就不啰嗦 */
    private function feeSuffix(CommissionWithdrawal $w): string
    {
        return $w->usdt_fee !== null && (float) $w->usdt_fee > 0
            ? "（已扣通道费 " . rtrim(rtrim((string) $w->usdt_fee, '0'), '.') . " USDT）"
            : '';
    }

    /**
     * 以某个账号（管理员 / 系统代用户）向工单写一条回复，并按需关闭工单。
     * 不走 TicketService::replyByAdmin —— 它会再发一封通用「工单有新回复」邮件，与这里的专用邮件重复。
     */
    private function replyTicketAsAdmin(CommissionWithdrawal $w, int $authorId, string $message, bool $close): void
    {
        if (!$w->ticket_id) {
            return;
        }
        $ticket = Ticket::find($w->ticket_id);
        if (!$ticket) {
            return;
        }
        TicketMessage::create([
            'user_id' => $authorId,
            'ticket_id' => $ticket->id,
            'message' => $message,
        ]);
        $ticket->reply_status = $authorId === $ticket->user_id ? Ticket::STATUS_CLOSED : Ticket::STATUS_OPENING;
        if ($close) {
            $ticket->status = Ticket::STATUS_CLOSED;
        }
        $ticket->save();
    }

    /**
     * 专用邮件（withdrawCompleted / withdrawRejected）。当前邮件主题目录里没有该模板时
     * （站长自定义主题）回落到通用 notify，正文用工单回复的纯文本。
     */
    private function notifyUser(CommissionWithdrawal $w, string $subject, string $content, string $template = 'notify'): void
    {
        $user = User::find($w->user_id);
        if (!$user || !$user->email) {
            return;
        }
        $theme = admin_setting('email_template', 'default');
        if ($template !== 'notify' && !View::exists("mail.{$theme}.{$template}")) {
            $template = 'notify';
        }
        $explorer = null;
        if ($w->txid) {
            $chain = $this->config->findChain($w->chain_code);
            if ($chain && $chain['explorer_tx'] !== '') {
                $explorer = str_replace('{txid}', rawurlencode($w->txid), $chain['explorer_tx']);
            }
        }
        try {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $subject . ' - ' . admin_setting('app_name', 'XBoard'),
                'template_name' => $template,
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => $content,
                    // 专用模板用的结构化字段
                    'withdrawal_id' => $w->id,
                    'amount' => $this->config->currencySymbol . number_format($w->amount / 100, 2, '.', ''),
                    'usdt' => $w->paid_usdt ?? $w->usdt_amount,
                    'usdt_is_actual' => $w->paid_usdt !== null,
                    'usdt_fee' => $w->usdt_fee !== null && (float) $w->usdt_fee > 0 ? rtrim(rtrim((string) $w->usdt_fee, '0'), '.') : null,
                    'usdt_rate' => $w->settle_rate ?? $w->usdt_rate,
                    'chain' => $w->network ? "{$w->chain_name} · {$w->network}" : $w->chain_name,
                    'address' => $w->address,
                    'txid' => $w->txid,
                    'explorer_url' => $explorer,
                    'reason' => $w->reject_reason,
                    'thanks' => $this->config->thanks,
                    'settled_at' => $w->settled_at ? date('Y-m-d H:i', $w->settled_at) : date('Y-m-d H:i'),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[withdraw] 邮件通知入队失败', ['withdrawal_id' => $w->id, 'error' => $e->getMessage()]);
        }
    }
}
