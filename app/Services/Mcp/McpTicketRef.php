<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Ticket;
use App\Models\User;

/**
 * 给 AI 用的工单编号：T + 8 位 HMAC，不是自增 ID（自增 ID 会让人推算出站点总工单数）。
 * 反查只在这个客户自己的工单里找，别人的编号对他来说就是「不存在」。
 */
final class McpTicketRef
{
    /** 反查时最多看这个客户最近多少张工单（客户的工单通常只有个位数） */
    private const LOOKUP_LIMIT = 500;

    public static function of(Ticket $ticket): string
    {
        return 'T' . strtoupper(substr(hash_hmac('sha256', 'mcp-ticket.' . $ticket->id, (string) config('app.key')), 0, 8));
    }

    /** @throws ApiException */
    public static function find(User $user, string $ref): Ticket
    {
        $ref = strtoupper(trim($ref));
        if (preg_match('/^T[0-9A-F]{8}$/', $ref)) {
            $tickets = Ticket::where('user_id', $user->id)->orderByDesc('id')->limit(self::LOOKUP_LIMIT)->get();
            foreach ($tickets as $ticket) {
                if (hash_equals(self::of($ticket), $ref)) {
                    return $ticket;
                }
            }
        }
        throw new ApiException('找不到这张工单。请先用 list_tickets 查看工单编号（ticket_ref）。');
    }
}
