<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AdvanceTrafficCycleTool;
use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\CancelWithdrawalTool;
use App\Mcp\Tools\CloseTicketTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\CreateRechargeTool;
use App\Mcp\Tools\CreateTicketTool;
use App\Mcp\Tools\GetAccountOverviewTool;
use App\Mcp\Tools\GetAccountSettingsTool;
use App\Mcp\Tools\GetInviteInfoTool;
use App\Mcp\Tools\GetPaygWalletTool;
use App\Mcp\Tools\GetPaymentLinkTool;
use App\Mcp\Tools\GetTrafficDailyTool;
use App\Mcp\Tools\GetTrafficOptionsTool;
use App\Mcp\Tools\ListBalanceLogTool;
use App\Mcp\Tools\ListNodesTool;
use App\Mcp\Tools\ListOffersTool;
use App\Mcp\Tools\ListOrdersTool;
use App\Mcp\Tools\ListPlansTool;
use App\Mcp\Tools\ListTicketsTool;
use App\Mcp\Tools\QuotePlanTool;
use App\Mcp\Tools\RecommendPlanTool;
use App\Mcp\Tools\RedeemGiftCardTool;
use App\Mcp\Tools\ReplyTicketTool;
use App\Mcp\Tools\ResetSubscriptionTool;
use App\Mcp\Tools\RestockAlertTool;
use App\Mcp\Tools\SearchHelpTool;
use App\Mcp\Tools\TransferCommissionTool;
use App\Mcp\Tools\UpdateAccountSettingsTool;
use App\Mcp\Tools\WithdrawCommissionTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * 客户 AI 助手（MCP）：客户把面板里生成的令牌填进自己的 AI，AI 就能替他查账户、按他的话下单。
 *
 * 入口 POST /mcp（routes/ai.php），门是 McpAuth。会改动账户的工具（下单、改设置、工单、兑换……）只对勾选了
 * 「允许 AI 替我操作」的令牌注册；钱永远由客户本人在免登录付款链接里付（OrderPayService），AI 碰不到支付，
 * 也拿不到订阅链接。每个工具都只读写令牌主人自己的账户：没有一个工具接受用户 ID / 邮箱参数。
 *
 * 佣金提现只能打到客户在面板填过的收款地址（McpCommission），第一次提现、换地址、传二维码留在面板。
 *
 * 刻意不给的面板功能：改密码、改邮箱、改收款地址、看订阅链接、登录会话管理、两步验证 / 账号安全。
 */
#[Version('1.4.0')]
#[Instructions(<<<'MD'
    你连接的是一个网络加速 / 住宅代理服务的客户账户。你代表的是账户主人本人。

    - 回答流量、到期、续费问题前先调用 get_account_overview；金额单位是人民币元，流量是 GB，时间是北京时间。
    - 客户问买哪个套餐、流量选多大、按量充多少、换套餐划不划算时，先调 recommend_plan：它按客户最近 90 天的真实用量（分线路，×3 中转、×10 三网都已换算成扣量）把包月各档和按量都算成「每月多少钱」，并给出推荐和理由。讲给客户：推荐哪个、每月大约多少钱、为什么（够不够用、要不要三网、包月和按量哪个省），再提一两个备选；客户选定后用它给的 quote_args 去 quote_plan。新客户没有用量记录时，先问清主要用途、每天大概用多久、家里宽带是电信 / 联通 / 移动，估出每月 GB 后带 monthly_gb 和 line 再调。只按客户的实际需要推荐，不要往贵的推。
    - 下单流程：先 list_plans / quote_plan 报价并让客户确认，再 create_order（选流量档、加三网等增值线路用 traffic_gb、addon_group_ids，报价和下单带同样的参数）。订单建好后把 payment_url 原样交给客户，由客户本人打开付款；你不能也不需要替客户付款。待付订单 2 小时内不付会自动取消。
    - 客户已有待付款订单时，先把那一单的付款链接给他（get_payment_link），不要重复下单。
    - 客户要买、续费、加购前先看 list_offers：有适用的优惠码就告诉客户，并在 quote_plan / create_order 带上 coupon_code。get_account_overview 里有 current_offers 时，可以在回答末尾顺带提一句活动，一次对话提一次就够，不要反复推销。
    - 流量不够时先调 get_traffic_options：projection 会照最近 7 天的速度算出到重置日还差多少、建议加购多少 GB；把加购流量、流量重置包、免费提前重置三种办法和代价讲给客户，让客户选。如果每个月都不够用，用 recommend_plan 看看是不是该换大一档或改按量。
    - 会改动账户的操作（提前重置流量、重置订阅链接、开关自动续费、提现、佣金转余额、提交工单、兑换礼品卡）先讲清后果、等客户明确同意再调用；带 confirm 参数的工具，第一次不带 confirm 只看后果。
    - 佣金：先 get_invite_info。提现用 withdraw_commission，只能打到客户在面板填过的收款地址，预览时把网络和地址头尾念给客户核对；没有收款地址就请客户到面板邀请页提现一次。转余额用 transfer_commission，转进余额后不能再提现，要先说清楚。
    - 订阅链接、节点地址、密码等敏感信息不会通过这里提供，请客户到面板查看；改密码、改邮箱、改收款地址也请客户到面板操作。
    - 退款、投诉、技术故障用 create_ticket 替客户提交工单（先确认内容），或用 reply_ticket 补充已有工单。
    - 客户想对这个 AI 助手提建议、吐槽体验时，用 create_ticket 提一张建议：category 用 suggestion，主题以「AI 助手建议」开头并写上你是哪个 AI 软件，正文照客户的原话整理，先念给客户确认。
    MD)]
class AccountServer extends Server
{
    /** tools/list 一页给全：默认 15 个一页，有的 AI 客户端不翻页，会看不到后面的下单类工具 */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        // 查询（只读令牌也有）
        GetAccountOverviewTool::class,
        GetTrafficDailyTool::class,
        GetTrafficOptionsTool::class,
        GetPaygWalletTool::class,
        GetAccountSettingsTool::class,
        ListOrdersTool::class,
        ListBalanceLogTool::class,
        RecommendPlanTool::class,
        ListPlansTool::class,
        ListOffersTool::class,
        QuotePlanTool::class,
        GetPaymentLinkTool::class,
        ListTicketsTool::class,
        GetInviteInfoTool::class,
        SearchHelpTool::class,
        ListNodesTool::class,
        // 操作（只对「允许 AI 替我操作」的令牌）
        CreateOrderTool::class,
        CreateRechargeTool::class,
        CancelOrderTool::class,
        UpdateAccountSettingsTool::class,
        AdvanceTrafficCycleTool::class,
        ResetSubscriptionTool::class,
        CreateTicketTool::class,
        ReplyTicketTool::class,
        CloseTicketTool::class,
        RedeemGiftCardTool::class,
        RestockAlertTool::class,
        WithdrawCommissionTool::class,
        CancelWithdrawalTool::class,
        TransferCommissionTool::class,
    ];

    protected function boot(): void
    {
        // 客户在 AI 的连接器列表里看到的名字：站点名
        $this->name = (string) admin_setting('app_name', 'XBoard');
    }
}
