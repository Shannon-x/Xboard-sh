<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 管理员两步验证记录（每个管理员至多一行）。密钥列走 encrypted cast，落库即密文。
 *
 * @property int $user_id
 * @property string|null $secret 已启用的 TOTP 密钥（Base32）
 * @property string|null $pending_secret 等待确认的新密钥
 * @property int|null $confirmed_at
 * @property array|null $recovery_codes 恢复码 bcrypt 哈希
 * @property int|null $last_used_step
 */
class AdminTwoFactor extends Model
{
    protected $table = 'v2_admin_two_factor';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    protected $dateFormat = 'U';
    protected $guarded = [];
    protected $hidden = ['secret', 'pending_secret', 'recovery_codes'];
    protected $casts = [
        'secret' => 'encrypted',
        'pending_secret' => 'encrypted',
        'recovery_codes' => 'array',
        'confirmed_at' => 'integer',
        'last_used_step' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isEnabled(): bool
    {
        return $this->confirmed_at !== null && !empty($this->secret);
    }

    public function recoveryCodesRemaining(): int
    {
        return is_array($this->recovery_codes) ? count($this->recovery_codes) : 0;
    }
}
