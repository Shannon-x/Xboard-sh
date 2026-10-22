<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardStatService;
use Illuminate\Http\Request;

/**
 * 新仪表盘的接口（路由仍挂在 stat 组下：stat/getDashboard、stat/getIncome）。
 * 单独成类，是因为 StatController 的构造函数会注入 StatisticalService，
 * 后者一构造就连 Redis、还把 memory_limit 调成 -1；仪表盘只读数据库和缓存，用不着这些。
 */
class DashboardController extends Controller
{
    /**
     * 新仪表盘的一次性数据：今日/本月收入（与昨日同时段、上月同期比）、用户、订阅、节点、流量、待办。
     * 口径见 DashboardStatService 顶部注释。
     */
    public function getDashboard(DashboardStatService $service)
    {
        return $this->success($service->dashboard());
    }

    /**
     * 收入趋势：按天的实收、付费订单数、佣金，含今天的实时数据，并给出同长度上一段做环比。
     * 直接按订单算（不读 v2_stat），所以今天也有数，汇总和按天的数字一定对得上。
     */
    public function getIncome(Request $request, DashboardStatService $service)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);
        $endDate = $request->input('end_date', now()->format('Y-m-d'));
        $startDate = $request->input('start_date', date('Y-m-d', strtotime('-29 day', strtotime($endDate))));
        if (strtotime($startDate) > strtotime($endDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        // 最多一年多一点，防止误传几十年的范围把订单表整张拉出来
        if (strtotime($endDate) - strtotime($startDate) > 400 * 86400) {
            $startDate = date('Y-m-d', strtotime('-399 day', strtotime($endDate)));
        }
        return $this->success($service->income($startDate, $endDate));
    }
}
