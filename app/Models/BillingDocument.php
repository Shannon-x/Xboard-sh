<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 归档的收据 / 续费账单。
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $order_id      收据对应的订单
 * @property string $kind            receipt | invoice
 * @property string $doc_no          RC-20261006-000123 / INV-20261105-000045
 * @property string|null $stage      账单：first（到期前 N 天）| final（到期前 24 小时）
 * @property int|null $expired_at    账单对应的到期时间戳
 * @property int $amount             收据 = 本单消耗金额，账单 = 应付金额（分）
 * @property string $access_key      下载链接里的随机凭据
 * @property string $path            文件路径 / 对象 key（相对 disk）
 * @property string $disk            文件在哪：local | s3（按写入时的配置记录，切换存储后旧文件仍按此读）
 * @property int $size               文件大小；0 = 文件已按保留期清理，下载时按订单重建
 * @property string $locale
 * @property int|null $sent_at       最近一次成功投递时间
 * @property int $send_count
 * @property string|null $channel    最近一次投递渠道 email | telegram
 * @property int $created_at
 * @property int $updated_at
 */
class BillingDocument extends Model
{
    public const KIND_RECEIPT = 'receipt';
    public const KIND_INVOICE = 'invoice';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';

    public const DISK_LOCAL = 'local';
    public const DISK_S3 = 's3';

    /** 账单过期多久仍未续费就视为作废（面板里不再标「待付款」） */
    public const VOID_AFTER_DAYS = 30;

    protected $table = 'v2_billing_document';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** 用户端下载路径（相对后端根）。同工单附件：<a href> 带不上 Bearer，凭据就是 URL 里的随机 key。 */
    public function downloadPath(): string
    {
        return "/api/v1/guest/billing/document/{$this->id}/{$this->access_key}";
    }

    /** 面板里展示的状态：收据恒为 paid；账单看用户现在的到期时间，续过费就是 settled，拖太久就是 void。 */
    public function statusFor(?User $user): string
    {
        if ($this->kind === self::KIND_RECEIPT) {
            return 'paid';
        }
        $expiry = (int) $this->expired_at;
        if ((int) ($user?->expired_at ?? 0) > $expiry) {
            return 'settled';
        }
        if ($expiry < time() - self::VOID_AFTER_DAYS * 86400) {
            return 'void';
        }
        return 'open';
    }
}
