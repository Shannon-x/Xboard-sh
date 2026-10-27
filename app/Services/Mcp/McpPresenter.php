<?php

namespace App\Services\Mcp;

use App\Models\Order;
use App\Models\Plan;
use App\Services\PlanService;

/**
 * 给 AI 看的数据格式：金额是「元」的字符串（两位小数），流量是 GB（两位小数），时间是北京时间。
 * 这里的任何输出都会进到第三方 AI 的对话记录里，所以只放客户自己看得懂、也不怕外泄的字段。
 */
final class McpPresenter
{
    public const BYTES_PER_GB = 1073741824;

    public static function yuan(int|float|null $cents): string
    {
        return number_format(((float) ($cents ?? 0)) / 100, 2, '.', '');
    }

    public static function gb(int|float|null $bytes): float
    {
        return round(((float) ($bytes ?? 0)) / self::BYTES_PER_GB, 2);
    }

    public static function time(?int $ts): ?string
    {
        return $ts ? date('Y-m-d H:i', $ts) : null;
    }

    public static function date(?int $ts): ?string
    {
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** 富文本 → 纯文本，截断 */
    public static function plain(?string $html, int $limit = 600): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div|\/li|\/h\d)\s*\/?>/i', "\n", (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace("/[ \t]+/u", ' ', (string) preg_replace("/\n{3,}/", "\n\n", $text)));
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }

    public static function periodName(string $period): string
    {
        $key = PlanService::getPeriodKey($period);
        if ($key === Plan::PERIOD_TRAFFIC_TOPUP) {
            return '流量加购';
        }
        return Plan::getAvailablePeriods()[$key]['name'] ?? $key;
    }

    public static function orderType(int $type): string
    {
        return match ($type) {
            Order::TYPE_NEW_PURCHASE => '新购',
            Order::TYPE_RENEWAL => '续费',
            Order::TYPE_UPGRADE => '变更套餐',
            Order::TYPE_RESET_TRAFFIC => '流量重置包',
            Order::TYPE_TRAFFIC_TOPUP => '流量加购',
            default => '其他',
        };
    }

    public static function orderStatus(int $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING => '待付款',
            Order::STATUS_PROCESSING => '开通中',
            Order::STATUS_CANCELLED => '已取消',
            Order::STATUS_COMPLETED => '已完成',
            Order::STATUS_DISCOUNTED => '已折抵',
            default => '未知',
        };
    }

    /** 不给 AI 看订阅链接：知识库正文里的占位符一律换成提示 */
    public static function scrubSubscribe(string $text): string
    {
        return str_replace(
            ['{{subscribeUrl}}', '{{urlEncodeSubscribeUrl}}', '{{safeBase64SubscribeUrl}}'],
            '（订阅链接请在面板「我的订阅」里复制）',
            $text
        );
    }

    public static function maskEmail(string $email): string
    {
        return \App\Services\Billing\BillingPayService::maskEmail($email);
    }
}
