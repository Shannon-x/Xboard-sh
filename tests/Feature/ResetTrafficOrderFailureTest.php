<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 回归测试：流量重置包订单在「重置失败」时必须让开通流程失败，不能静默完成。
 *
 * 背景（真实缺陷）：
 *   OrderService::open() 的 match 分支曾直接写
 *       Plan::PERIOD_RESET_TRAFFIC => app(TrafficResetService::class)->performReset(...)
 *   把返回值丢掉了。而 performReset 内部 catch(\Exception) 后只记日志并 return false，
 *   u/d 清零随内层事务回滚。结果是：用户付钱买了重置包 → 流量一点没恢复 →
 *   订单却被置成 COMPLETED（终态，cron 不会再重试）→ 钱白花且无自愈路径。
 *   任何注册在 traffic.reset.after 上的插件 hook 抛异常都能触发。
 *
 * 修复后语义与套餐变更路径（buyByPeriod 内）一致：抛异常 → open() 外层事务回滚 →
 * 订单停在 PROCESSING → check:order 每分钟重投 OrderHandleJob 重试。
 *
 * 本测试不碰数据库：只把 TrafficResetService 换成桩件，直接驱动私有方法，
 * 因此不依赖 migration 在 SQLite 上的可用性。
 */
class ResetTrafficOrderFailureTest extends TestCase
{
    private function invokeResetTrafficForOrder(bool $resetResult): void
    {
        $this->app->instance(
            TrafficResetService::class,
            new class ($resetResult) extends TrafficResetService {
                public function __construct(private bool $result)
                {
                }

                public function performReset(User $user, string $triggerSource = 'manual'): bool
                {
                    return $this->result;
                }
            }
        );

        // 刻意不给构造函数传属性：Order 的 $guarded 会让 fill() 去查表结构，
        // 而这套测试不跑 migration。resetTrafficForOrder() 也只用到 $this->user。
        $order = new Order();
        $order->period = Plan::PERIOD_RESET_TRAFFIC;

        $service = new OrderService($order);
        $service->user = new User();

        $method = new ReflectionMethod($service, 'resetTrafficForOrder');
        $method->setAccessible(true);
        $method->invoke($service);
    }

    public function test_reset_failure_aborts_order_open(): void
    {
        $this->expectException(\RuntimeException::class);

        // performReset 返回 false（内部已吞掉异常）时，开通流程必须中止，
        // 否则订单会被置成 COMPLETED 而流量并未重置。
        $this->invokeResetTrafficForOrder(false);
    }

    public function test_reset_success_lets_order_open_continue(): void
    {
        $this->invokeResetTrafficForOrder(true);

        // 没有抛异常即为通过：正常路径不能被这道守卫误伤。
        $this->assertTrue(true);
    }

    /**
     * 守卫住「返回值必须被消费」这件事本身：
     * open() 的 reset_traffic 分支若又被改回直接调用 performReset，此测试会失败。
     */
    public function test_open_routes_reset_traffic_through_the_guarded_helper(): void
    {
        $source = file_get_contents(app_path('Services/OrderService.php'));

        $this->assertMatchesRegularExpression(
            '/Plan::PERIOD_RESET_TRAFFIC\s*=>\s*\$this->resetTrafficForOrder\(\)/',
            $source,
            'open() 的 reset_traffic 分支必须走 resetTrafficForOrder()，不能直接调用 performReset 并丢弃返回值'
        );
    }
}
