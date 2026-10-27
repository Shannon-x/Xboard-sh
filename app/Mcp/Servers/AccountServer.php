<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\CreateRechargeTool;
use App\Mcp\Tools\GetAccountOverviewTool;
use App\Mcp\Tools\GetPaygWalletTool;
use App\Mcp\Tools\GetPaymentLinkTool;
use App\Mcp\Tools\GetTrafficDailyTool;
use App\Mcp\Tools\ListNodesTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListPlansTool;
use App\Mcp\Tools\ListTicketsTool;
use App\Mcp\Tools\QuotePlanTool;
use App\Mcp\Tools\SearchHelpTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * 客户 AI 助手（MCP）：客户把面板里生成的令牌填进自己的 AI，AI 就能替他查账户、按他的话下单。
 *
 * 入口 POST /mcp（routes/ai.php），门是 McpAuth。下单类工具只对勾选了「允许下单」的令牌注册，
 * 钱永远由客户本人在免登录付款链接里付（OrderPayService），AI 碰不到支付，也拿不到订阅链接。
 */
#[Version('1.0.0')]
#[Instructions(<<<'MD'
    你连接的是一个网络加速 / 住宅代理服务的客户账户。你代表的是账户主人本人。

    - 回答流量、到期、续费问题前先调用 get_account_overview；金额单位是人民币元，流量是 GB，时间是北京时间。
    - 下单流程：先 list_plans / quote_plan 报价并让客户确认，再 create_order。订单建好后把 payment_url 原样交给客户，由客户本人打开付款；你不能也不需要替客户付款。待付订单 2 小时内不付会自动取消。
    - 客户已有待付款订单时，先把那一单的付款链接给他（get_payment_link），不要重复下单。
    - 订阅链接、节点地址、密码等敏感信息不会通过这里提供，请客户到面板查看。
    - 退款、投诉、技术故障请引导客户到面板提交工单。
    MD)]
class AccountServer extends Server
{
    protected array $tools = [
        GetAccountOverviewTool::class,
        GetTrafficDailyTool::class,
        GetPaygWalletTool::class,
        ListOrdersTool::class,
        ListPlansTool::class,
        QuotePlanTool::class,
        GetPaymentLinkTool::class,
        CreateOrderTool::class,
        CreateRechargeTool::class,
        CancelOrderTool::class,
        ListTicketsTool::class,
        SearchHelpTool::class,
        ListNodesTool::class,
    ];

    protected function boot(): void
    {
        // 客户在 AI 的连接器列表里看到的名字：站点名
        $this->name = (string) admin_setting('app_name', 'XBoard');
    }
}
