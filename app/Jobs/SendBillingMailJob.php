<?php

namespace App\Jobs;

use App\Models\CommissionWithdrawal;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingDocumentService;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * 收据 / 续费账单 / 提现结果邮件：payload 只带 id，到 worker 里才组装数据、渲染 PDF。
 * PDF 是二进制，塞进 payload 会让 json_encode 失败；重试时按当时的订单 / 用户状态重新生成也更稳。
 * 与普通邮件同走 send_email 队列，复用 MailService 的后台 SMTP 配置与 v2_mail_log。
 */
class SendBillingMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const KIND_RECEIPT = 'receipt';
    public const KIND_INVOICE = 'invoice';
    public const KIND_WITHDRAWAL = 'withdrawal';

    public $tries = 3;
    public $timeout = 60;   // 首次渲染要建字体度量缓存，比普通邮件慢得多

    /**
     * @param int|null $expiry 账单对应的到期时间戳：派发到执行之间用户续了费（expired_at 变了）就作废这张账单
     */
    public function __construct(public string $kind, public int $id, public ?string $stage = null, public ?int $expiry = null)
    {
        $this->onQueue('send_email');
    }

    /** 事务提交后再派发：OrderHandleJob 把 open() 包在事务里，回滚了就不该发收据。 */
    public static function dispatchReceipt(Order $order): void
    {
        self::dispatch(self::KIND_RECEIPT, (int) $order->id)->afterCommit();
    }

    public static function dispatchInvoice(User $user, string $stage): void
    {
        self::dispatch(self::KIND_INVOICE, (int) $user->id, $stage, (int) $user->expired_at);
    }

    /** 打款 / 驳回后派发；邮件内容到执行时再按记录当时的状态组装。 */
    public static function dispatchWithdrawal(CommissionWithdrawal $withdrawal): void
    {
        self::dispatch(self::KIND_WITHDRAWAL, (int) $withdrawal->id)->afterCommit();
    }

    public function handle(BillingDocumentService $docs): void
    {
        // mPDF 嵌中文字体时峰值 ~40MB，生产 memory_limit=128M 够用；只在被压得更低时抬一下
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < 128 * 1024 * 1024) {
            ini_set('memory_limit', '128M');
        }
        $log = BillingDocumentService::withLocale(fn () => match ($this->kind) {
            self::KIND_RECEIPT => $this->sendReceipt($docs),
            self::KIND_WITHDRAWAL => $this->sendWithdrawal($docs),
            default => $this->sendInvoice($docs),
        });
        if ($log !== null && $log['error']) {
            $this->release(30);   // 与 SendEmailJob 一致：发送失败触发重试
        }
    }

    private function sendReceipt(BillingDocumentService $docs): ?array
    {
        $order = Order::find($this->id);
        if (!$order || (int) $order->status !== Order::STATUS_COMPLETED || $order->receipt_sent_at) {
            return null;
        }
        $data = $docs->receipt($order);
        if ($data === null) {
            return null;   // 0 元单 / 用户没邮箱：没有收据可开
        }
        $log = MailService::deliver($data['bill_to']['email'], $data['subject'], 'billing.mail.receipt', $data, [
            ['name' => $docs->attachmentName($data), 'data' => $docs->pdf($data), 'mime' => 'application/pdf'],
        ]);
        if (!$log['error']) {
            DB::table('v2_order')->where('id', $order->id)->update(['receipt_sent_at' => time()]);
        }
        return $log;
    }

    private function sendInvoice(BillingDocumentService $docs): ?array
    {
        $user = User::find($this->id);
        if (!$user || !$user->email || $user->banned || !$user->remind_expire || !$user->plan_id) {
            return null;
        }
        $expiredAt = (int) $user->expired_at;
        if ($expiredAt <= time() || ($this->expiry !== null && $expiredAt !== $this->expiry)) {
            return null;   // 已过期，或派发后已续费：这张账单作废
        }
        $data = $docs->invoice($user, (string) $this->stage);
        // 24 小时档且自动续费马上会扣款（每小时跑一次）：收据随后就到，这封提醒只是噪音
        if ($this->stage === BillingDocumentService::STAGE_FINAL && $data['auto_covered']) {
            return null;
        }
        $attachments = $data['has_pdf']
            ? [['name' => $docs->attachmentName($data), 'data' => $docs->pdf($data), 'mime' => 'application/pdf']]
            : [];
        return MailService::deliver($user->email, $data['subject'], 'billing.mail.invoice', $data, $attachments);
    }

    private function sendWithdrawal(BillingDocumentService $docs): ?array
    {
        $withdrawal = CommissionWithdrawal::find($this->id);
        $data = $withdrawal ? $docs->withdrawal($withdrawal) : null;
        if ($data === null) {
            return null;   // 记录没了 / 还在待处理 / 用户自己取消的：没有邮件可发
        }
        return MailService::deliver($data['bill_to']['email'], $data['subject'], 'billing.mail.withdrawal', $data);
    }

    private static function bytes(string $ini): int
    {
        if ($ini === '' || $ini === '-1') return 0;
        $unit = strtolower(substr($ini, -1));
        $n = (int) $ini;
        return match ($unit) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }
}
