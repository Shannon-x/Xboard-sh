<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserNotificationPref;
use App\Services\Mcp\McpPresenter as P;
use App\Services\Notification\NotificationPreference;
use App\Services\Payg\PaygConfig;
use App\Services\Payg\PaygService;
use App\Services\RenewService;

/**
 * 账号设置里客户自己能开关的几项，给 AI 看与改：自动续费、按量自动补充、邮件通知类别。
 * 与面板「账号设置」同一份数据、同一套写入（UserController::update / NotificationController::save）。
 */
final class McpAccountSettings
{
    /** 通知类别的中文名，与主题 notify.category.*.title 一致 */
    public const CATEGORY_NAMES = [
        NotificationPreference::BILLING => '账单与到期提醒',
        NotificationPreference::USAGE => '流量与用量',
        NotificationPreference::SUPPORT => '工单回复',
        NotificationPreference::COMMISSION => '推广与佣金',
        NotificationPreference::ANNOUNCEMENT => '服务公告',
        NotificationPreference::MARKETING => '活动与优惠',
    ];

    public static function view(User $user): array
    {
        $user = User::findOrFail($user->id);
        $renew = app(RenewService::class);
        $spec = $renew->resolveSpec($user);
        $auto = $renew->autoState($user, $spec);
        $payg = PaygConfig::fromSettings();

        return [
            'auto_renew' => [
                'enabled' => $auto['enabled'],
                'available_on_site' => $auto['site_enabled'],
                'renews' => $spec['available'] ? "{$spec['plan_name']} · {$spec['period_name']}" : null,
                'renew_amount_yuan' => $auto['amount'] !== null ? P::yuan($auto['amount']) : null,
                'account_balance_yuan' => P::yuan($auto['balance']),
                'balance_enough' => $auto['balance_enough'],
                'shortfall_yuan' => $auto['shortfall'] !== null ? P::yuan($auto['shortfall']) : null,
                'runs_hours_before_expiry' => $auto['lead_hours'],
                'cannot_renew_reason' => $spec['available'] ? null : $spec['message'],
                'how_it_works' => '到期前按上面的规格用账户余额自动续一次；余额不够就不续，只发提醒邮件，不会留下扣了一半的订单。',
            ],
            'payg_auto_topup' => PaygService::isWallet($user, $payg) ? [
                'enabled' => (bool) $user->payg_auto_topup,
                'available_on_site' => $payg->autoTopupEnable,
                'how_it_works' => '按量流量快用完时，自动用账户余额再买一份基础包。',
            ] : null,
            'email_notifications' => array_map(fn (array $c) => [
                'category' => $c['key'],
                'name' => self::CATEGORY_NAMES[$c['key']] ?? $c['key'],
                'enabled' => $c['enabled'],
                'can_turn_off' => !$c['locked'],
            ], NotificationPreference::view($user)['categories']),
            'always_sent' => '收据、提现结果、验证码与安全提醒始终发送，不能关。',
        ];
    }

    /**
     * @param array{auto_renew?: bool, payg_auto_topup?: bool, email_notifications?: array<string, bool>} $changes
     * @throws ApiException
     */
    public static function apply(User $user, array $changes, ?string $ip): array
    {
        $user = User::findOrFail($user->id);
        $done = [];

        if (array_key_exists('auto_renew', $changes) && $changes['auto_renew'] !== null) {
            $on = (bool) $changes['auto_renew'];
            if ($on && !RenewService::siteEnabled()) {
                throw new ApiException('本站暂未开放自动续费。');
            }
            $user->auto_renew = $on;
            $done[] = $on ? '已开启自动续费' : '已关闭自动续费';
        }

        if (array_key_exists('payg_auto_topup', $changes) && $changes['payg_auto_topup'] !== null) {
            $on = (bool) $changes['payg_auto_topup'];
            $payg = PaygConfig::fromSettings();
            if (!PaygService::isWallet($user, $payg)) {
                throw new ApiException('当前不是按量套餐，没有按量自动补充可开关。');
            }
            if ($on && !$payg->autoTopupEnable) {
                throw new ApiException('本站暂未开放按量自动补充。');
            }
            $user->payg_auto_topup = $on;
            $done[] = $on ? '已开启按量自动补充' : '已关闭按量自动补充';
        }

        $prefs = [];
        foreach ((array) ($changes['email_notifications'] ?? []) as $category => $enabled) {
            $category = (string) $category;
            if (!NotificationPreference::isCategory($category)) {
                throw new ApiException("没有这个通知类别：{$category}。可用：" . implode('、', array_keys(self::CATEGORY_NAMES)) . '。');
            }
            if (!is_bool($enabled)) {
                throw new ApiException("通知类别 {$category} 的值要是 true 或 false。");
            }
            if (!$enabled && !in_array($category, NotificationPreference::optionalCategories(), true)) {
                throw new ApiException('「' . self::CATEGORY_NAMES[$category] . '」本站规定始终发送，不能关闭。');
            }
            $prefs[$category] = $enabled;
            $done[] = ($enabled ? '已开启' : '已关闭') . '「' . self::CATEGORY_NAMES[$category] . '」邮件';
        }

        if ($done === []) {
            throw new ApiException('没有要修改的设置：可以改 auto_renew、payg_auto_topup 或 email_notifications。');
        }
        if ($user->isDirty()) {
            $user->save();
        }
        if ($prefs !== []) {
            NotificationPreference::setMany($user, $prefs, UserNotificationPref::SOURCE_MCP, $ip);
        }

        return ['changed' => $done, 'settings' => self::view($user)];
    }
}
