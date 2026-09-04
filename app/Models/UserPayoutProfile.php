<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用户上次提现用的收款信息，下次申请自动预填；二维码指向工单附件，可沿用。
 *
 * @property int $id
 * @property int $user_id
 * @property string $chain_code
 * @property string $address
 * @property int|null $qr_attachment_id
 * @property int $created_at
 * @property int $updated_at
 */
class UserPayoutProfile extends Model
{
    protected $table = 'v2_user_payout_profile';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function qrAttachment(): BelongsTo
    {
        return $this->belongsTo(TicketAttachment::class, 'qr_attachment_id', 'id');
    }
}
