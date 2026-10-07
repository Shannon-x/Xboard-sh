<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开出的收据 / 续费账单。不存 PDF 文件：payload 是开具那一刻的内容快照，下载 / 重发时按它现生成 PDF，
 * 所以事后改了邮箱、套餐名、站点信息，用户拿到的仍是当初那份。
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $order_id      收据对应的订单
 * @property int|null $pay_order_id  账单通过免登录付款链接下的订单（BillingPayService）；取消了会被下一单覆盖
 * @property string $kind            receipt | invoice
 * @property string $doc_no          RC-20261007-7KQ2M9XA / INV-20261105-K3D8W1QZ（见 BillingDocumentService::documentNumber）
 * @property string|null $stage      账单：first（到期前 N 天）| final（到期前 24 小时）
 * @property int|null $expired_at    账单对应的到期时间戳
 * @property int $amount             收据 = 本单消耗金额，账单 = 应付金额（分）
 * @property string $access_key      签下载链接用的随机密钥（不直接出现在链接里）
 * @property array|null $payload     内容快照（BillingDocumentService 产出的渲染数据，不含 logo 图片）
 * @property int $size               快照字节数（后台统计占用用）
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

    /** 账单过期多久仍未续费就视为作废（面板里不再标「待付款」） */
    public const VOID_AFTER_DAYS = 30;

    /** 下载链接有效期：用户端 1 小时（页面开着会提前换新），后台 12 小时 */
    public const LINK_TTL = 3600;
    public const ADMIN_LINK_TTL = 43200;

    protected $table = 'v2_billing_document';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        // 中文不转义成 \uXXXX：同样内容少占一半多空间
        'payload' => 'json:unicode',
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

    public function payOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'pay_order_id');
    }

    /**
     * 下载路径（相对后端根）。<a href> 带不上 Bearer，凭据在 URL 里：8 位十六进制的过期时间 +
     * 用这份单据的 access_key 对「id.过期时间」做的签名。链接到期就失效，外泄（截图、共享电脑的
     * 历史记录）也只在这一小段时间里有用；面板列表每次拉取都发新的，用户感觉不到。
     */
    public function downloadPath(int $expires): string
    {
        return sprintf('/api/v1/guest/billing/document/%d/%08x%s', $this->id, $expires, $this->linkSignature($expires));
    }

    public function linkSignature(int $expires): string
    {
        return substr(hash_hmac('sha256', $this->id . '.' . $expires, (string) $this->access_key), 0, 32);
    }

    /**
     * 免登录付款链接的凭据：id 加上用 access_key 对「pay.id」做的签名。一张账单一个、整个有效期内不变
     * （邮件里的按钮和 PDF 上印的地址要一直能用），能不能付由服务端按账单现状判断（BillingPayService）；
     * 签名只证明「拿到链接的人收到过这封邮件」。access_key 自己不出现在链接里。
     */
    public function payToken(): string
    {
        return $this->id . '-' . $this->paySignature();
    }

    public function paySignature(): string
    {
        return substr(hash_hmac('sha256', 'pay.' . $this->id, (string) $this->access_key), 0, 32);
    }

    /** 用户端前端的付款页路径（相对 app_url） */
    public function payPath(): string
    {
        return '/pay/' . $this->payToken();
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
