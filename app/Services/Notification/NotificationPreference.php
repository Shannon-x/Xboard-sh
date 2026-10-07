<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Models\UserNotificationPref;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 用户通知偏好：发信侧唯一的判断入口，面板 / 邮件链接 / 一键退订 / 后台都从这里改。
 *
 * 类别（与 WHMCS 的「邮件偏好」同构，少几类）：
 *   billing       账单与到期提醒：续费账单（到期前 N 天 / 24 小时）、老的到期提醒、到期当天的「服务已暂停」、自动续费的结果通知
 *   usage         流量与用量：流量用到 80% 的提醒
 *   marketing     营销与活动：到期后的挽回邮件、后台群发里标为「营销」的邮件
 *   announcement  服务公告：后台群发默认的类别（维护、线路调整这类）
 *   support       工单：工单有新回复
 *   交易类（收据、提现结果、验证码、登录链接、邮箱自测）不属于任何类别，永远发送，面板里只展示不给关。
 *
 * 存储：v2_user_notification_pref 每用户每类别一行（没有行 = 开启），source 记这次取值从哪来。
 * billing / usage 同时镜像到老列 remind_expire / remind_traffic —— 扫描任务（send:remindMail）按这两列筛用户、
 * 老前端与后台也还读写它们；读取时这两类以老列为准，保证两边永远一致。
 *
 * 后台可配置哪些类别允许用户关闭（notify_optional_categories，默认全部五类）：锁定的类别面板里显示为灰色「始终发送」，
 * 用户已关掉的也照发。
 */
final class NotificationPreference
{
    public const BILLING = 'billing';
    public const USAGE = 'usage';
    public const MARKETING = 'marketing';
    public const ANNOUNCEMENT = 'announcement';
    public const SUPPORT = 'support';

    /** 面板展示顺序 */
    public const CATEGORIES = [self::BILLING, self::USAGE, self::SUPPORT, self::ANNOUNCEMENT, self::MARKETING];

    /** 批量 / 推广性质的类别：这几类邮件带 List-Unsubscribe 头（Gmail / Yahoo 对批量发件人的送达率要求） */
    public const BULK = [self::MARKETING, self::ANNOUNCEMENT];

    /** 老列镜像 */
    private const LEGACY_COLUMN = [
        self::BILLING => 'remind_expire',
        self::USAGE => 'remind_traffic',
    ];

    /** 永远发送、面板里只展示的交易类通知（给前端列出来用，不是类别） */
    public const ALWAYS = ['receipt', 'withdrawal', 'security'];

    public static function isCategory(?string $category): bool
    {
        return $category !== null && in_array($category, self::CATEGORIES, true);
    }

    /** 后台允许用户自己关闭的类别 */
    public static function optionalCategories(): array
    {
        $raw = admin_setting('notify_optional_categories');
        if ($raw === null || $raw === '') {
            return self::CATEGORIES;
        }
        $list = array_values(array_intersect(self::CATEGORIES, array_map('trim', explode(',', (string) $raw))));
        return $list;
    }

    public static function isOptional(string $category): bool
    {
        return in_array($category, self::optionalCategories(), true);
    }

    /** 批量类邮件要不要带 List-Unsubscribe 头 */
    public static function listUnsubscribeEnabled(): bool
    {
        return (bool) (int) admin_setting('notify_list_unsubscribe_enable', 1);
    }

    /** 邮件页脚那行小字链接的文案；后台留空用字典里的默认值 */
    public static function footerLabel(): string
    {
        $label = trim((string) admin_setting('notify_footer_label', ''));
        return $label !== '' ? $label : __('billing.footer.manage');
    }

    // ---------------------------------------------------------------- 读取

    /**
     * 这封邮件发不发。$category 为 null（交易类）永远 true；类别被后台锁定也 true。
     *
     * @param User|int|null $user 用户或 id；查不到用户按「发」处理（比如管理员邮箱）
     */
    public static function allows(User|int|null $user, ?string $category): bool
    {
        if (!self::isCategory($category)) {
            return true;
        }
        if (!self::isOptional($category)) {
            return true;
        }
        $user = is_int($user) ? User::find($user) : $user;
        if (!$user) {
            return true;
        }
        return self::enabled($user, $category);
    }

    /** 用户的实际取值（不看后台锁定） */
    public static function enabled(User $user, string $category): bool
    {
        if (isset(self::LEGACY_COLUMN[$category])) {
            $column = self::LEGACY_COLUMN[$category];
            $value = $user->getAttribute($column);
            if ($value === null && !array_key_exists($column, $user->getAttributes())) {
                // 扫描任务只 select 了部分列时兜底查一次
                $value = User::where('id', $user->id)->value($column);
            }
            return (bool) $value;
        }
        $row = UserNotificationPref::where('user_id', $user->id)->where('category', $category)->first();
        return $row ? (bool) $row->enabled : true;
    }

    /**
     * 面板 / 偏好页用的完整视图。
     *
     * @return array{categories: list<array{key: string, enabled: bool, locked: bool, source: string|null, updated_at: int|null}>, always: list<string>}
     */
    public static function view(User $user): array
    {
        $rows = UserNotificationPref::where('user_id', $user->id)->get()->keyBy('category');
        $optional = self::optionalCategories();
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            /** @var UserNotificationPref|null $row */
            $row = $rows->get($category);
            $categories[] = [
                'key' => $category,
                'enabled' => self::enabled($user, $category),
                'locked' => !in_array($category, $optional, true),
                'source' => $row?->source,
                'updated_at' => $row ? (int) $row->updated_at : null,
            ];
        }
        return ['categories' => $categories, 'always' => self::ALWAYS];
    }

    // ---------------------------------------------------------------- 写入

    /**
     * 改一类。锁定的类别也允许写（后台解锁后用户的选择立即生效），只是当前不生效。
     */
    public static function set(User $user, string $category, bool $enabled, string $source, ?string $ip = null): void
    {
        if (!self::isCategory($category)) {
            return;
        }
        if (!in_array($source, UserNotificationPref::SOURCES, true)) {
            $source = UserNotificationPref::SOURCE_PANEL;
        }
        $now = time();
        DB::transaction(function () use ($user, $category, $enabled, $source, $ip, $now) {
            $row = UserNotificationPref::where('user_id', $user->id)->where('category', $category)->lockForUpdate()->first();
            if ($row) {
                if ((bool) $row->enabled !== $enabled || $row->source !== $source) {
                    $row->fill(['enabled' => $enabled, 'source' => $source, 'ip' => $ip, 'updated_at' => $now])->save();
                }
            } else {
                UserNotificationPref::create([
                    'user_id' => $user->id, 'category' => $category, 'enabled' => $enabled,
                    'source' => $source, 'ip' => $ip, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if (isset(self::LEGACY_COLUMN[$category])) {
                $column = self::LEGACY_COLUMN[$category];
                User::where('id', $user->id)->update([$column => $enabled ? 1 : 0]);
                $user->setAttribute($column, $enabled);
            }
        });
        if (!$enabled) {
            Log::info('[notify] 用户关闭了一类通知', ['user_id' => $user->id, 'category' => $category, 'source' => $source]);
        }
    }

    /** @param array<string, bool> $prefs */
    public static function setMany(User $user, array $prefs, string $source, ?string $ip = null): void
    {
        foreach ($prefs as $category => $enabled) {
            self::set($user, (string) $category, (bool) $enabled, $source, $ip);
        }
    }

    /**
     * 老接口 /user/update 直接改了 remind_expire / remind_traffic：把变化同步成一行记录（source=legacy），
     * 后台看得到是从哪来的，也不会出现「行说关、列说开」。
     */
    public static function syncLegacy(User $user, array $changes, string $source = UserNotificationPref::SOURCE_LEGACY, ?string $ip = null): void
    {
        foreach (self::LEGACY_COLUMN as $category => $column) {
            if (array_key_exists($column, $changes)) {
                self::set($user, $category, (bool) (int) $changes[$column], $source, $ip);
            }
        }
    }

    // ---------------------------------------------------------------- 免登录凭据

    /** 用户的免登录偏好凭据：128 位随机，首次用到时生成并存下 */
    public static function key(User $user): string
    {
        if ($user->notify_key) {
            return (string) $user->notify_key;
        }
        $key = bin2hex(random_bytes(16));
        // 并发下只有第一个写得进去；写不进去就用库里已有的
        $updated = User::where('id', $user->id)->whereNull('notify_key')->update(['notify_key' => $key]);
        if (!$updated) {
            $key = (string) User::where('id', $user->id)->value('notify_key');
        }
        $user->setAttribute('notify_key', $key);
        return $key;
    }

    public static function resolve(string $key): ?User
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            return null;
        }
        return User::where('notify_key', $key)->first();
    }

    /** 换一把钥匙：旧邮件里的链接全部失效 */
    public static function rotate(User $user): string
    {
        $key = bin2hex(random_bytes(16));
        User::where('id', $user->id)->update(['notify_key' => $key]);
        $user->setAttribute('notify_key', $key);
        return $key;
    }

    /** 用户端前端的偏好页（相对 app_url）：/notify/<凭据> */
    public static function managePath(User $user): string
    {
        return '/notify/' . self::key($user);
    }

    public static function manageUrl(User $user): string
    {
        return self::appUrl() . self::managePath($user);
    }

    /**
     * List-Unsubscribe 头里的地址：邮件客户端 POST 一下就把这一类关掉（RFC 8058 一键退订），GET 打开就跳到偏好页。
     * 经用户端域名的 /api 转发到后端（中间件对这条路径明文直通，与支付回调同一机制）。
     */
    public static function unsubscribeUrl(User $user, string $category): string
    {
        return self::appUrl() . '/api/v1/guest/notify/unsubscribe/' . self::key($user) . '/' . $category;
    }

    private static function appUrl(): string
    {
        return rtrim((string) admin_setting('app_url', ''), '/');
    }

    /** 邮箱打码：ab***@example.com */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
        return $keep . '***' . ($domain !== '' ? '@' . $domain : '');
    }
}
