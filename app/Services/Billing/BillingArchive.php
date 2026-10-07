<?php

namespace App\Services\Billing;

use App\Models\BillingDocument;
use App\Models\Order;
use App\Models\User;

/**
 * 收据 / 账单的开具记录。不存 PDF 文件：每份记一行，payload 是开具那一刻的内容快照
 * （BillingDocumentService 产出的渲染数据，约 2 KB），下载、重发、Telegram 兜底时按快照现生成 PDF。
 *
 * 这样事后改了邮箱、套餐名、站点信息或用户续了费，用户拿到的仍是当初那份；只有版式、logo 和站内链接的域名
 * 跟着站点现状走。没有快照的老记录（升级前开的）按原始数据重建一次并补存快照。
 */
final class BillingArchive
{
    public function storeReceipt(Order $order, array $data): BillingDocument
    {
        // 按 kind 找：免登录付款把账单也挂在同一个 order_id 上，收据要另起一行，不能覆盖那张账单
        $doc = BillingDocument::where('order_id', $order->id)->where('kind', BillingDocument::KIND_RECEIPT)->first() ?: new BillingDocument();
        return $this->fill($doc, [
            'user_id' => (int) $order->user_id,
            'order_id' => (int) $order->id,
            'kind' => BillingDocument::KIND_RECEIPT,
            'stage' => null,
            'expired_at' => null,
            'amount' => (int) $data['total'],
        ], $data);
    }

    /**
     * 同一个到期日只留一份账单：24 小时档覆盖 7 天档的快照（金额按当时规格重算，编号不变）。
     * 账单在付款前本来就会变，付清或失效后就不再更新。pay_order_id 不动：7 天档的链接已经下了单的话，
     * 24 小时档刷新快照时要保住，再打开链接接着付那一单。
     *
     * 免登录付款链接要用这条记录的 id 和 access_key 签名，只能在记录存下之后补进快照（邮件正文、
     * PDF 和 Telegram 文字都从存好的快照出）。自动续费余额够的账单不放：那封邮件只是告知。
     */
    public function storeInvoice(User $user, string $stage, array $data): BillingDocument
    {
        $expiry = (int) $user->expired_at;
        $doc = BillingDocument::where('user_id', $user->id)
            ->where('kind', BillingDocument::KIND_INVOICE)
            ->where('expired_at', $expiry)
            ->first() ?: new BillingDocument();
        $doc = $this->fill($doc, [
            'user_id' => (int) $user->id,
            'kind' => BillingDocument::KIND_INVOICE,
            'stage' => $stage,
            'expired_at' => $expiry,
            'amount' => (int) $data['balance_due'],
        ], $data);
        if (empty($data['auto_covered']) && BillingPayService::linkable($doc)) {
            $data['pay_url'] = BillingPayService::url($doc);
            $data['cta_url'] = $data['pay_url'];
            $data['cta_label'] = __('billing.invoice.cta_pay');
            $data['pay_note'] = BillingDocumentService::payNote();
            $this->snapshot($doc, $data);
            $doc->save();
        }
        return $doc;
    }

    public function markSent(BillingDocument $doc, string $channel): void
    {
        $doc->sent_at = time();
        $doc->send_count = (int) $doc->send_count + 1;
        $doc->channel = $channel;
        $doc->save();
    }

    /** 按快照现生成 PDF（下载用）；快照和重建都拿不到时返回 null（下载接口回 404）。 */
    public function contents(BillingDocument $doc, BillingDocumentService $docs): ?string
    {
        $data = $this->data($doc, $docs);
        if ($data === null) {
            return null;
        }
        return BillingDocumentService::withLocale(fn () => $docs->pdf($this->withStatus($doc, $data)), $doc->locale ?: null);
    }

    /**
     * 账单的内容固定在开具那一刻，「待付款」却不是：此后续了费或过期失效，下载时盖上对应的章、
     * 换掉付款提示，免得用户以为还欠着钱。金额与明细照旧；收据恒为已付款，不受影响。
     */
    private function withStatus(BillingDocument $doc, array $data): array
    {
        if ($doc->kind !== BillingDocument::KIND_INVOICE) {
            return $data;
        }
        $status = $doc->statusFor(User::find($doc->user_id));
        if ($status === 'open') {
            return $data;
        }
        $data['stamp'] = __('billing.invoice.stamp_' . $status);
        $data['stamp_soft'] = true;
        $data['closed_note'] = __('billing.invoice.closed_' . $status);
        $data['alternatives'] = [];   // 开具时的套餐推荐和价格早已过时，关了的账单不再带
        return $data;
    }

    /**
     * 这份单据的渲染数据：有快照就用快照；没有（升级前开的老记录）按原始数据重建一次并补存，
     * 之后就固定下来。账单重建只在它仍是当前到期日时可行。
     */
    public function data(BillingDocument $doc, BillingDocumentService $docs): ?array
    {
        if (is_array($doc->payload) && $doc->payload !== []) {
            return $this->present($doc->payload);
        }
        $data = BillingDocumentService::withLocale(fn () => $this->rebuildData($doc, $docs), $doc->locale ?: null);
        if ($data === null) {
            return null;
        }
        $this->snapshot($doc, $data);
        $doc->save();
        return $data;
    }

    /** 按订单 / 用户现状重建渲染数据：收据只要订单在就能重建，账单只在仍是当前到期日且还没到期时能。 */
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
     * 快照内容原样用，只有两样跟着站点现状走：logo（不进快照），和站内链接的域名 ——
     * 站点换过域名后，重发的旧收据里「查看账户」之类的链接仍要能点开。
     */
    private function present(array $data): array
    {
        $data['logo_url'] = BrandLogo::url();
        $data['logo_size'] = BrandLogo::mailSize();
        $old = rtrim((string) ($data['app_url'] ?? ''), '/');
        $new = rtrim((string) admin_setting('app_url', ''), '/');
        if ($old !== '' && $new !== '' && $old !== $new) {
            array_walk_recursive($data, function (&$value) use ($old, $new) {
                if (is_string($value) && ($value === $old || str_starts_with($value, $old . '/') || str_starts_with($value, $old . '?'))) {
                    $value = $new . substr($value, strlen($old));
                }
            });
        }
        return $data;
    }

    /** 记下快照（不保存记录，由调用方 save）。logo 不进快照：PDF 里嵌的图几十 KB，且跟着站点走。 */
    private function snapshot(BillingDocument $doc, array $data): void
    {
        unset($data['logo_data'], $data['logo_url'], $data['logo_size']);
        $doc->payload = $data;
        $doc->size = strlen(json_encode($data, JSON_UNESCAPED_UNICODE) ?: '');   // 与 json:unicode 转换同样的编码，字节数和库里一致
    }

    private function fill(BillingDocument $doc, array $attrs, array $data): BillingDocument
    {
        $doc->fill($attrs + [
            'doc_no' => (string) $data['doc_no'],
            'access_key' => $doc->access_key ?: bin2hex(random_bytes(16)),
            'locale' => (string) ($data['locale'] ?? 'zh-CN'),
        ]);
        $this->snapshot($doc, $data);
        $doc->save();
        return $doc;
    }
}
