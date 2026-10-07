<?php

namespace App\Jobs;

use App\Models\BillingDocument;
use App\Models\CommissionWithdrawal;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;
use App\Services\Mail\DeliveryMonitor;
use App\Services\MailService;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 收据 / 续费账单 / 提现结果 / 到期后通知邮件：payload 只带 id，到 worker 里才组装数据、渲染 PDF
 * （PDF 是二进制，塞进 payload 会让 json_encode 失败）。
 *
 * 顺序是「先记录，再投递」：收据和账单先把内容快照记入 v2_billing_document（不存 PDF 文件，下载时按快照现生成），
 * 用户随时能从面板重新下载、后台能重发；之后才尝试邮件。收据开过一次就固定，重发 / 重试都照快照发。
 * 邮件退信（地址被抑制 / 不存在）时转 Telegram 发同一份文件，两条路都走不通就只留在面板里
 * （后台「待送达文件」能看到）。临时失败和 SMTP 配置问题才重试。
 */
class SendBillingMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const KIND_RECEIPT = 'receipt';
    public const KIND_INVOICE = 'invoice';
    public const KIND_WITHDRAWAL = 'withdrawal';
    public const KIND_EXPIRED = 'expired';     // 到期当天：服务已暂停
    public const KIND_WINBACK = 'winback';     // 到期后第 N 天：挽回

    public const RESULT_EMAIL = 'email';
    public const RESULT_TELEGRAM = 'telegram';
    public const RESULT_SKIPPED = 'skipped';   // 没发出去也不会再试：没有内容可发 / 用户被标记且没有 Telegram
    public const RESULT_RETRY = 'retry';       // 临时失败：已 release，队列稍后重试

    public $tries = 3;
    public $timeout = 60;   // 首次渲染要建字体度量缓存，比普通邮件慢得多

    /** 最近一次 handle() 的结果，供日志与测试查看 */
    public ?string $result = null;

    /**
     * @param int|null $expiry 邮件对应的到期时间戳：派发到执行之间用户续了费（expired_at 变了）就作废这封邮件
     * @param bool $force 后台重发：忽略「已发过」标记（receipt_sent_at / 自动续费将覆盖）
     */
    public function __construct(public string $kind, public int $id, public ?string $stage = null, public ?int $expiry = null, public bool $force = false)
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

    /** 到期后序列：第 1 档是「服务已暂停」，之后各档是挽回邮件（档号从 2 起，对应 billing_winback_days 的顺序）。 */
    public static function dispatchLifecycle(User $user, int $stage): void
    {
        self::dispatch($stage === 1 ? self::KIND_EXPIRED : self::KIND_WINBACK, (int) $user->id, (string) $stage, (int) $user->expired_at);
    }

    public static function resendReceipt(Order $order): void
    {
        self::dispatch(self::KIND_RECEIPT, (int) $order->id, null, null, true);
    }

    public static function resendInvoice(User $user, BillingDocument $doc): void
    {
        self::dispatch(self::KIND_INVOICE, (int) $user->id, (string) ($doc->stage ?: BillingDocumentService::STAGE_FIRST), (int) $doc->expired_at, true);
    }

    public function handle(BillingDocumentService $docs, BillingArchive $archive): void
    {
        // mPDF 嵌中文字体时峰值 ~40MB，生产 memory_limit=128M 够用；只在被压得更低时抬一下
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < 128 * 1024 * 1024) {
            ini_set('memory_limit', '128M');
        }
        $this->result = BillingDocumentService::withLocale(fn () => match ($this->kind) {
            self::KIND_RECEIPT => $this->sendReceipt($docs, $archive),
            self::KIND_WITHDRAWAL => $this->sendWithdrawal($docs),
            self::KIND_EXPIRED => $this->sendExpired($docs),
            self::KIND_WINBACK => $this->sendWinback($docs),
            default => $this->sendInvoice($docs, $archive),
        }) ?? self::RESULT_SKIPPED;
        if ($this->result === self::RESULT_RETRY) {
            $this->release(30);   // 与 SendEmailJob 一致：临时失败触发重试，退信不重试
        }
    }

    private function sendReceipt(BillingDocumentService $docs, BillingArchive $archive): ?string
    {
        $order = Order::find($this->id);
        if (!$order || (int) $order->status !== Order::STATUS_COMPLETED) {
            return null;
        }
        if ($order->receipt_sent_at && !$this->force) {
            return null;
        }
        $doc = BillingDocument::where('order_id', $order->id)->first();
        if ($doc) {
            // 已经开过（后台重发 / 队列重试）：照开具时的快照原样再发，不按订单现状重开一张
            $data = $archive->data($doc, $docs);
        } else {
            $data = $docs->receipt($order);
            if ($data !== null) {
                $doc = $archive->storeReceipt($order, $data);   // 先记下：邮件发不出去用户也能在面板下载
            }
        }
        if ($data === null) {
            return null;   // 0 元单 / 用户没邮箱：没有收据可开
        }
        $user = $order->user;
        $pdf = BillingDocumentService::withLocale(fn () => $docs->pdf($data), $doc->locale ?: null);
        $result = $this->notify($user, $data, 'billing.mail.receipt', $pdf, $doc, $docs, $archive);
        if ($result === self::RESULT_EMAIL || $result === self::RESULT_TELEGRAM) {
            DB::table('v2_order')->where('id', $order->id)->update(['receipt_sent_at' => time()]);
        }
        return $result;
    }

    private function sendInvoice(BillingDocumentService $docs, BillingArchive $archive): ?string
    {
        $user = User::find($this->id);
        if (!$user || !$user->email || $user->banned || !$user->remind_expire || !$user->plan_id) {
            return null;
        }
        $expiredAt = (int) $user->expired_at;
        if ($expiredAt <= time() || ($this->expiry !== null && $expiredAt !== $this->expiry)) {
            return null;   // 已过期，或派发后已续费：这张账单作废
        }
        $stage = (string) ($this->stage ?: BillingDocumentService::STAGE_FIRST);
        $data = $docs->invoice($user, $stage);
        // 24 小时档且自动续费马上会扣款（每小时跑一次）：收据随后就到，这封提醒只是噪音
        if ($stage === BillingDocumentService::STAGE_FINAL && $data['auto_covered'] && !$this->force) {
            return null;
        }
        $pdf = null;
        $doc = null;
        if ($data['has_pdf']) {
            $pdf = $docs->pdf($data);
            $doc = $archive->storeInvoice($user, $stage, $data);
        }
        return $this->notify($user, $data, 'billing.mail.invoice', $pdf, $doc, $docs, $archive);
    }

    private function sendWithdrawal(BillingDocumentService $docs): ?string
    {
        $withdrawal = CommissionWithdrawal::find($this->id);
        $data = $withdrawal ? $docs->withdrawal($withdrawal) : null;
        if ($data === null) {
            return null;   // 记录没了 / 还在待处理 / 用户自己取消的：没有邮件可发
        }
        $user = User::find($withdrawal->user_id);
        if (!$user) {
            return null;
        }
        return $this->notify($user, $data, 'billing.mail.withdrawal', null, null, $docs, null);
    }

    private function sendExpired(BillingDocumentService $docs): ?string
    {
        $user = $this->lifecycleUser();
        if ($user === null) {
            return null;
        }
        return $this->notify($user, $docs->expired($user), 'billing.mail.expired', null, null, $docs, null);
    }

    private function sendWinback(BillingDocumentService $docs): ?string
    {
        $user = $this->lifecycleUser();
        if ($user === null) {
            return null;
        }
        return $this->notify($user, $docs->winback($user, (int) $this->stage), 'billing.mail.winback', null, null, $docs, null);
    }

    /** 到期后邮件的收件人：派发后续了费（expired_at 变了或又在未来）、封禁、关了提醒的都不发。 */
    private function lifecycleUser(): ?User
    {
        $user = User::find($this->id);
        if (!$user || !$user->email || $user->banned || !$user->remind_expire || !$user->plan_id) {
            return null;
        }
        $expiredAt = (int) $user->expired_at;
        if ($expiredAt <= 0 || $expiredAt > time() || ($this->expiry !== null && $expiredAt !== $this->expiry)) {
            return null;
        }
        return $user;
    }

    /**
     * 投递：没被标记的先走邮件；成功记 sent；临时失败 / 配置问题交给队列重试；
     * 退信（这一次就被标记）或本来就被标记的转 Telegram；都不行就只留在面板。
     */
    private function notify(User $user, array $data, string $view, ?string $pdf, ?BillingDocument $doc, BillingDocumentService $docs, ?BillingArchive $archive): string
    {
        // 收件人永远是用户现在的邮箱：重发旧收据时快照里的「账单寄往」可能是改之前的地址
        $email = (string) ($user->email ?: ($data['bill_to']['email'] ?? ''));
        $attachments = $pdf !== null
            ? [['name' => $docs->attachmentName($data), 'data' => $pdf, 'mime' => 'application/pdf']]
            : [];
        if (!DeliveryMonitor::suppressed($user)) {
            $log = MailService::deliver($email, (string) $data['subject'], $view, $data, $attachments, (int) $user->id);
            if (!$log['error']) {
                if ($doc && $archive) {
                    $archive->markSent($doc, BillingDocument::CHANNEL_EMAIL);
                }
                return self::RESULT_EMAIL;
            }
            if (DeliveryMonitor::isRetryable($log['category'])) {
                return self::RESULT_RETRY;
            }
            // 退信：DeliveryMonitor 已把用户标记为暂停投递，下面改走 Telegram
        }
        if ($this->telegram($user, $data, $pdf, $docs)) {
            if ($doc && $archive) {
                $archive->markSent($doc, BillingDocument::CHANNEL_TELEGRAM);
            }
            return self::RESULT_TELEGRAM;
        }
        Log::info('[billing] 邮件未能送达且无 Telegram，文件仅留在面板', ['user_id' => $user->id, 'kind' => $this->kind, 'document_id' => $doc?->id]);
        return self::RESULT_SKIPPED;
    }

    /** Telegram 兜底：有 PDF 就发文件（caption 带主题与入口），没有就发文字。失败只记日志，不抛出。 */
    private function telegram(User $user, array $data, ?string $pdf, BillingDocumentService $docs): bool
    {
        if (!(int) admin_setting('telegram_bot_enable', 0) || !$user->telegram_id) {
            return false;
        }
        $intro = trim(html_entity_decode(strip_tags((string) ($data['intro'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $text = trim((string) $data['subject'] . "\n\n" . $intro . "\n\n" . (string) ($data['cta_url'] ?? ''));
        try {
            $telegram = app(TelegramService::class);
            if ($pdf !== null) {
                $telegram->sendDocument((int) $user->telegram_id, $pdf, $docs->attachmentName($data), $text);
            } else {
                $telegram->sendMessage((int) $user->telegram_id, $text);
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[billing] Telegram 兜底发送失败', ['user_id' => $user->id, 'kind' => $this->kind, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function bytes(string $ini): int
    {
        if ($ini === '' || $ini === '-1') return 0;
        $unit = strtolower(substr($ini, -1));
        $n = (int) $ini;
        return match ($unit) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }
}
