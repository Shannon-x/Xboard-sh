<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 一张已付订单 / 充值单的网关事后核验结果，见 App\Services\PaymentVerificationService。
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $user_id
 * @property int|null $payment_id
 * @property string|null $callback_no
 * @property int $expected_amount
 * @property string $verdict
 * @property int|null $gateway_status
 * @property int|null $gateway_money
 * @property string|null $gateway_type
 * @property int $attempts
 * @property int $miss_count
 * @property string|null $last_error
 * @property int|null $checked_at
 * @property int|null $next_check_at
 * @property int|null $alerted_at
 */
class PaymentVerification extends Model
{
    protected $table = 'v2_payment_verification';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'subject_id' => 'integer',
        'user_id' => 'integer',
        'payment_id' => 'integer',
        'expected_amount' => 'integer',
        'gateway_status' => 'integer',
        'gateway_money' => 'integer',
        'attempts' => 'integer',
        'miss_count' => 'integer',
        'checked_at' => 'integer',
        'next_check_at' => 'integer',
        'alerted_at' => 'integer',
        'recheck_stage' => 'integer',
        'recheck_at' => 'integer',
        'refunded_at' => 'integer',
        'enforce_snapshot' => 'array',
    ];

    public const SUBJECT_ORDER = 'order';
    public const SUBJECT_RECHARGE = 'recharge';

    /** 还没有结论：网关暂时查不到，等下一次重试 */
    public const VERDICT_PENDING = 'pending';
    /** 网关有这笔、已付、金额与单号一致 */
    public const VERDICT_OK = 'ok';
    /** 重试多次网关仍然没有这笔交易 */
    public const VERDICT_NOT_FOUND = 'not_found';
    /** 网关有这笔，但金额或单号对不上 */
    public const VERDICT_MISMATCH = 'mismatch';
    /** 网关有这笔，但状态不是已付（未付成功或已退款） */
    public const VERDICT_REFUNDED = 'refunded';
    /** 网络 / 网关 / 鉴权出错，稍后重试 */
    public const VERDICT_ERROR = 'error';
    /** 不适用网关核验（人工补单、链上直付、找不到支付配置等） */
    public const VERDICT_SKIPPED = 'skipped';

    /** 需要人工处理的终态结论 */
    public const ANOMALIES = [self::VERDICT_NOT_FOUND, self::VERDICT_MISMATCH, self::VERDICT_REFUNDED];

    /** 还要再查的结论 */
    public const RETRYABLE = [self::VERDICT_PENDING, self::VERDICT_ERROR];

    public const LABELS = [
        self::VERDICT_PENDING => '待重试',
        self::VERDICT_OK => '一致',
        self::VERDICT_NOT_FOUND => '网关查不到',
        self::VERDICT_MISMATCH => '金额或单号不符',
        self::VERDICT_REFUNDED => '网关未付或已退款',
        self::VERDICT_ERROR => '查询出错',
        self::VERDICT_SKIPPED => '跳过',
    ];
}
