<?php

namespace App\Console\Commands;

use App\Jobs\OrderHandleJob;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class CheckOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:order';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '订单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 只派发 worker 真会处理的订单：
        //   · 待支付且已超过取消时限（OrderHandleJob::PENDING_CANCEL_AFTER）—— 要取消；
        //   · 开通中（支付回调已翻状态但 open() 没走完、或当时 Horizon 没起来）—— 要补开通。
        // 原先把所有待支付单每分钟都派一遍：两小时内的新单到了 worker 只是 lockForUpdate 后原样返回，
        // 白占 business supervisor，并给 MySQL 带来「待支付单数」个空事务 / 分钟。
        //
        // 不再 orderBy('created_at')：lazyById 按 id 游标分页，叠加别的排序会让第二页起重复 / 漏掉订单
        //（待处理单超过一页 200 条时实测 325 单派出 404 个任务）。id 单调递增，按 id 升序就是先创建先处理。
        $cancelBefore = time() - OrderHandleJob::PENDING_CANCEL_AFTER;
        Order::where(function ($query) use ($cancelBefore) {
            $query->where(function ($pending) use ($cancelBefore) {
                $pending->where('status', Order::STATUS_PENDING)
                    ->where('created_at', '<=', $cancelBefore);
            })->orWhere('status', Order::STATUS_PROCESSING);
        })
            ->lazyById(200)
            ->each(function ($order) {
                OrderHandleJob::dispatch($order->trade_no);
            });
    }
}
