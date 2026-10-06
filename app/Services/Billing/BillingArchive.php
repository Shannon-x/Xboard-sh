<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * 收据 / 账单归档：PDF 落盘到 storage/app/billing/documents/<user_id>/<doc_no>.pdf，表里记一行；
 * 用户端按 access_key 下载，后台可重发。邮件退信、附件被剥、用户想再要一份 —— 都从这里拿。
 *
 * 文件丢了（迁移、volume 没挂）时收据按订单重新渲染（订单字段不会变），
 * 账单只在它仍是当前到期日时重渲染；再拿不到就 404。
 */
final class BillingArchive
{
    public const DISK = 'local';
    public const DIR = 'billing/documents';

    public function storeReceipt(Order $order, array $data, string $pdf): BillingDocument
    {
        $doc = BillingDocument::where('order_id', $order->id)->first() ?: new BillingDocument();
        return $this->fill($doc, [
            'user_id' => (int) $order->user_id,
            'order_id' => (int) $order->id,
            'kind' => BillingDocument::KIND_RECEIPT,
            'stage' => null,
            'expired_at' => null,
            'amount' => (int) $data['total'],
        ], $data, $pdf);
    }

    /** 同一个到期日只留一份账单：24 小时档覆盖 7 天档的文件（金额按当时规格重算，编号不变）。 */
    public function storeInvoice(User $user, string $stage, array $data, string $pdf): BillingDocument
    {
        $expiry = (int) $user->expired_at;
        $doc = BillingDocument::where('user_id', $user->id)
            ->where('kind', BillingDocument::KIND_INVOICE)
            ->where('expired_at', $expiry)
            ->first() ?: new BillingDocument();
        return $this->fill($doc, [
            'user_id' => (int) $user->id,
            'order_id' => null,
            'kind' => BillingDocument::KIND_INVOICE,
            'stage' => $stage,
            'expired_at' => $expiry,
            'amount' => (int) $data['balance_due'],
        ], $data, $pdf);
    }

    public function markSent(BillingDocument $doc, string $channel): void
    {
        $doc->sent_at = time();
        $doc->send_count = (int) $doc->send_count + 1;
        $doc->channel = $channel;
        $doc->save();
    }

    /** PDF 内容；文件不在了就按原始数据重渲染并补回磁盘，重渲染也不行返回 null。 */
    public function contents(BillingDocument $doc, BillingDocumentService $docs): ?string
    {
        $disk = Storage::disk(self::DISK);
        if ($doc->path && $disk->exists($doc->path)) {
            return $disk->get($doc->path);
        }
        $pdf = BillingDocumentService::withLocale(function () use ($doc, $docs) {
            $data = $this->rebuildData($doc, $docs);
            return $data === null ? null : $docs->pdf($data);
        });
        if ($pdf === null) {
            return null;
        }
        $disk->put($doc->path, $pdf);
        $doc->size = strlen($pdf);
        $doc->save();
        return $pdf;
    }

    /** 重发 / 重渲染用的原始数据：收据永远能重建，账单只在仍是当前到期日且还没到期时能。 */
    public function rebuildData(BillingDocument $doc, BillingDocumentService $docs): ?array
    {
        if ($doc->kind === BillingDocument::KIND_RECEIPT) {
            $order = $doc->order_id ? Order::find($doc->order_id) : null;
            return $order ? $docs->receipt($order) : null;
        }
        $user = User::find($doc->user_id);
        if (!$user || (int) $user->expired_at !== (int) $doc->expired_at || (int) $user->expired_at <= time()) {
            return null;
        }
        $data = $docs->invoice($user, (string) ($doc->stage ?: BillingDocumentService::STAGE_FIRST));
        return $data['has_pdf'] ? $data : null;
    }

    private function fill(BillingDocument $doc, array $attrs, array $data, string $pdf): BillingDocument
    {
        $path = self::DIR . '/' . $attrs['user_id'] . '/' . $data['doc_no'] . '.pdf';
        Storage::disk(self::DISK)->put($path, $pdf);
        $doc->fill($attrs + [
            'doc_no' => (string) $data['doc_no'],
            'access_key' => $doc->access_key ?: bin2hex(random_bytes(16)),
            'path' => $path,
            'size' => strlen($pdf),
            'locale' => (string) ($data['locale'] ?? 'zh-CN'),
        ]);
        $doc->save();
        return $doc;
    }
}
