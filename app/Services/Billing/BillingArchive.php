<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\Storage\DocumentStore;
use App\Services\Billing\Storage\DocumentStoreFactory;
use Illuminate\Support\Facades\Log;

/**
 * 收据 / 账单归档：PDF 写到当前配置的存储（本地 storage/app/billing/documents/<user_id>/<doc_no>.pdf，
 * 或 S3 兼容对象存储的 <prefix>/<user_id>/<doc_no>.pdf），表里记一行（path + disk 指向文件在哪）；
 * 用户端按 access_key 下载，后台可重发。邮件退信、附件被剥、用户想再要一份 —— 都从这里拿。
 *
 * 文件不在了（迁移、volume 没挂、按保留期清理掉了）时收据按订单重新渲染（订单字段不会变），
 * 账单只在它仍是当前到期日时重渲染；再拿不到就 404。保留期清理见 billing:prune-documents。
 */
final class BillingArchive
{
    private ?BillingStorageConfig $config = null;

    /** 当前配置的存储；传 $driver 则按库里记录的 disk 取（读 / 删旧文件用） */
    public function store(?string $driver = null): DocumentStore
    {
        // 实例不是单例（每个任务 / 请求各自解析一份），配置在实例内记住即可，清理任务跑几千份不必每份都读一遍设置
        $this->config ??= BillingStorageConfig::fromSettings();
        return DocumentStoreFactory::make($this->config, $driver);
    }

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

    /** PDF 内容；文件不在了就按原始数据重渲染并补回存储，重渲染也不行返回 null。 */
    public function contents(BillingDocument $doc, BillingDocumentService $docs): ?string
    {
        $pdf = $this->read($doc);
        if ($pdf !== null) {
            return $pdf;
        }
        $pdf = BillingDocumentService::withLocale(function () use ($doc, $docs) {
            $data = $this->rebuildData($doc, $docs);
            return $data === null ? null : $docs->pdf($data);
        });
        if ($pdf === null) {
            return null;
        }
        $this->write($doc, $pdf);
        $doc->save();
        return $pdf;
    }

    /**
     * 按记录里的位置读文件。已被清理（size = 0）或不在了返回 null；存储报错也当不在，
     * 由调用方重建 —— 下载接口不该因为对象存储抖一下就 500。
     */
    public function read(BillingDocument $doc): ?string
    {
        if (!$doc->path || (int) $doc->size <= 0) {
            return null;
        }
        try {
            return $this->store($doc->disk ?: BillingStorageConfig::DRIVER_LOCAL)->get($doc->path);
        } catch (\Throwable $e) {
            Log::warning('[billing] 读取归档文件失败', [
                'id' => $doc->id, 'disk' => $doc->disk, 'path' => $doc->path, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 按记录里的位置删文件（不存在视为成功）。失败只记日志并返回 false，
     * 让调用方保留记录下一轮再试；否则库里干净了、对象却永远留在存储上。
     */
    public function deleteFile(BillingDocument $doc): bool
    {
        if (!$doc->path) {
            return true;
        }
        try {
            $this->store($doc->disk ?: BillingStorageConfig::DRIVER_LOCAL)->delete($doc->path);
            return true;
        } catch (\Throwable $e) {
            Log::warning('[billing] 删除归档文件失败', [
                'id' => $doc->id, 'disk' => $doc->disk, 'path' => $doc->path, 'error' => $e->getMessage(),
            ]);
            return false;
        }
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

    /**
     * 把 PDF 写到当前配置的存储并更新记录的 path / disk / size（不保存记录，由调用方 save）。
     *
     * 对象存储写不进去时退回本地盘：归档失败不能拖住邮件；文件在哪由 disk 列说了算，
     * 之后 billing:migrate-storage 可以再搬过去。位置变了（换了驱动 / 前缀）就顺手删掉旧文件。
     */
    private function write(BillingDocument $doc, string $pdf): void
    {
        $store = $this->store();
        $key = $store->keyFor((int) $doc->user_id, (string) $doc->doc_no);
        try {
            $store->put($key, $pdf);
        } catch (\Throwable $e) {
            if ($store->driver() === BillingStorageConfig::DRIVER_LOCAL) {
                throw $e;
            }
            Log::warning('[billing] 对象存储写入失败，本次改存本地', ['doc_no' => $doc->doc_no, 'error' => $e->getMessage()]);
            $store = $this->store(BillingStorageConfig::DRIVER_LOCAL);
            $key = $store->keyFor((int) $doc->user_id, (string) $doc->doc_no);
            $store->put($key, $pdf);
        }
        $moved = $doc->path && ($doc->path !== $key || ($doc->disk ?: BillingStorageConfig::DRIVER_LOCAL) !== $store->driver());
        if ($moved && (int) $doc->size > 0) {
            $this->deleteFile($doc);
        }
        $doc->path = $key;
        $doc->disk = $store->driver();
        $doc->size = strlen($pdf);
    }

    private function fill(BillingDocument $doc, array $attrs, array $data, string $pdf): BillingDocument
    {
        $doc->fill($attrs + [
            'doc_no' => (string) $data['doc_no'],
            'access_key' => $doc->access_key ?: bin2hex(random_bytes(16)),
            'locale' => (string) ($data['locale'] ?? 'zh-CN'),
        ]);
        $this->write($doc, $pdf);
        $doc->save();
        return $doc;
    }
}
