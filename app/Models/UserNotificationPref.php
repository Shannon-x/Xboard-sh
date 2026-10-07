<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用户对某一类通知的取舍。没有行 = 默认开启；行只在用户（或后台）动过开关后才出现。
 *
 * @property int $id
 * @property int $user_id
 * @property string $category  App\Services\Notification\NotificationPreference::CATEGORIES 之一
 * @property bool $enabled
 * @property string $source    这次取值从哪来：panel（面板）| email_link（邮件页脚链接）| list_unsubscribe（邮件客户端一键退订）| admin | legacy（老的 /user/update）
 * @property string|null $ip
 * @property int $created_at
 * @property int $updated_at
 */
class UserNotificationPref extends Model
{
    public const SOURCE_PANEL = 'panel';
    public const SOURCE_EMAIL_LINK = 'email_link';
    public const SOURCE_LIST_UNSUBSCRIBE = 'list_unsubscribe';
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_LEGACY = 'legacy';

    public const SOURCES = [
        self::SOURCE_PANEL,
        self::SOURCE_EMAIL_LINK,
        self::SOURCE_LIST_UNSUBSCRIBE,
        self::SOURCE_ADMIN,
        self::SOURCE_LEGACY,
    ];

    protected $table = 'v2_user_notification_pref';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
