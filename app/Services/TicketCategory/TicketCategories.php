<?php

namespace App\Services\TicketCategory;

use App\Models\Ticket;

/**
 * 工单分类与「建议 / 反馈」处理状态的唯一定义。
 *
 * 分类 code 是前后端之间的契约：用户前端按 code 取多语言文案，后台按 code 筛选统计，
 * 所以 code 一旦上线就不要改名，只能新增或在后台隐藏（ticket_category_hidden）。
 * 这里的中文名只给后台、Telegram 通知和邮件用，用户前端显示的是主题里的 i18n 文案。
 */
final class TicketCategories
{
    public const TYPE_SUPPORT = 0;   // 求助：要客服解决的问题
    public const TYPE_FEEDBACK = 1;  // 建议与反馈：不需要「解决」，需要「答复 + 跟进状态」

    public const DEFAULT_CATEGORY = 'other';

    /**
     * code => [type, 中文名, 用户能否自己选]
     * 顺序即前端展示顺序。
     */
    public const CATEGORIES = [
        'connection' => [self::TYPE_SUPPORT, '节点与连接', true],
        'client' => [self::TYPE_SUPPORT, '客户端使用', true],
        'subscription' => [self::TYPE_SUPPORT, '订阅与套餐', true],
        'billing' => [self::TYPE_SUPPORT, '支付与订单', true],
        'account' => [self::TYPE_SUPPORT, '账号与安全', true],
        'other' => [self::TYPE_SUPPORT, '其他问题', true],
        'suggestion' => [self::TYPE_FEEDBACK, '功能建议', true],
        'experience' => [self::TYPE_FEEDBACK, '意见与体验', true],
        // 系统建单专用（佣金提现自动开的工单），用户不能手选
        'withdraw' => [self::TYPE_SUPPORT, '佣金提现', false],
    ];

    /**
     * 建议 / 反馈的跟进状态。求助类工单这个字段恒为 null。
     * 用户端按这个状态画进度条，所以顺序有意义：received → accepted → planned → shipped，
     * declined 是终态分支。
     */
    public const FEEDBACK_RECEIVED = 'received';
    public const FEEDBACK_ACCEPTED = 'accepted';
    public const FEEDBACK_PLANNED = 'planned';
    public const FEEDBACK_SHIPPED = 'shipped';
    public const FEEDBACK_DECLINED = 'declined';

    public const FEEDBACK_STATES = [
        self::FEEDBACK_RECEIVED => '已收到',
        self::FEEDBACK_ACCEPTED => '已采纳',
        self::FEEDBACK_PLANNED => '排期中',
        self::FEEDBACK_SHIPPED => '已上线',
        self::FEEDBACK_DECLINED => '暂不采纳',
    ];

    /** 同一用户同时开着的建议 / 反馈上限（求助类仍是「同时只能开一张」） */
    public const MAX_OPEN_FEEDBACK = 3;

    public static function exists(?string $code): bool
    {
        return $code !== null && isset(self::CATEGORIES[$code]);
    }

    public static function typeOf(?string $code): int
    {
        return self::CATEGORIES[$code][0] ?? self::TYPE_SUPPORT;
    }

    public static function nameOf(?string $code): string
    {
        return self::CATEGORIES[$code][1] ?? self::CATEGORIES[self::DEFAULT_CATEGORY][1];
    }

    public static function isFeedbackState(?string $state): bool
    {
        return $state !== null && isset(self::FEEDBACK_STATES[$state]);
    }

    /**
     * 后台隐藏的分类（逗号分隔的 code）。隐藏只影响「用户新建时能不能选」，
     * 存量工单照常显示、照常能被筛选。
     *
     * @return string[]
     */
    public static function hiddenCodes(): array
    {
        $raw = (string) admin_setting('ticket_category_hidden', '');
        $codes = array_filter(array_map('trim', preg_split('/[,，;\s]+/u', $raw) ?: []));
        return array_values(array_intersect($codes, array_keys(self::CATEGORIES)));
    }

    public static function feedbackEnabled(): bool
    {
        return (bool) (int) admin_setting('ticket_feedback_enable', 1);
    }

    /**
     * 用户新建工单时可选的分类 code。
     *
     * @return string[]
     */
    public static function selectableCodes(): array
    {
        $hidden = self::hiddenCodes();
        $feedback = self::feedbackEnabled();
        $codes = [];
        foreach (self::CATEGORIES as $code => [$type, , $selectable]) {
            if (!$selectable || in_array($code, $hidden, true)) {
                continue;
            }
            if ($type === self::TYPE_FEEDBACK && !$feedback) {
                continue;
            }
            $codes[] = $code;
        }
        // 「其他问题」是兜底，永远可选 —— 否则老前端不传 category 时没有落点
        if (!in_array(self::DEFAULT_CATEGORY, $codes, true)) {
            $codes[] = self::DEFAULT_CATEGORY;
        }
        return $codes;
    }

    /**
     * 给用户前端的公开配置（/user/comm/config 的 ticket_categories）。
     *
     * @return array<int, array{code:string,type:int,name:string}>
     */
    public static function toPublicArray(): array
    {
        return array_map(fn(string $code) => [
            'code' => $code,
            'type' => self::typeOf($code),
            'name' => self::nameOf($code),
        ], self::selectableCodes());
    }

    /**
     * 给后台的完整字典：所有分类（含隐藏的、系统专用的）+ 反馈状态。
     *
     * @return array{categories: array<int, array{code:string,type:int,name:string,selectable:bool,hidden:bool}>, feedback_states: array<int, array{code:string,name:string}>}
     */
    public static function toAdminArray(): array
    {
        $hidden = self::hiddenCodes();
        $categories = [];
        foreach (self::CATEGORIES as $code => [$type, $name, $selectable]) {
            $categories[] = [
                'code' => $code,
                'type' => $type,
                'name' => $name,
                'selectable' => $selectable,
                'hidden' => in_array($code, $hidden, true),
            ];
        }
        $states = [];
        foreach (self::FEEDBACK_STATES as $code => $name) {
            $states[] = ['code' => $code, 'name' => $name];
        }
        return ['categories' => $categories, 'feedback_states' => $states];
    }

    /** Telegram / 邮件里的一行标签：「节点与连接」或「功能建议 · 排期中」 */
    public static function label(Ticket $ticket): string
    {
        $label = self::nameOf($ticket->category);
        if ((int) $ticket->type === self::TYPE_FEEDBACK && self::isFeedbackState($ticket->feedback_state)) {
            $label .= ' · ' . self::FEEDBACK_STATES[$ticket->feedback_state];
        }
        return $label;
    }
}
