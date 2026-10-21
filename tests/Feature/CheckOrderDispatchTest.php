<?php

namespace Tests\Feature;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** check:order 只派发 worker 真会处理的订单，且分页不重不漏。 */
class CheckOrderDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function order(int $status, int $createdAt): string
    {
        $tradeNo = 'T' . str_pad((string) ++$this->seq, 6, '0', STR_PAD_LEFT);
        DB::table('v2_order')->insert([
            'user_id' => 1, 'plan_id' => 1, 'type' => Order::TYPE_NEW_PURCHASE, 'period' => 'month_price', 'trade_no' => $tradeNo,
            'total_amount' => 1000, 'status' => $status, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        return $tradeNo;
    }

    #[Test]
    public function only_expired_pending_and_processing_orders_are_dispatched(): void
    {
        Queue::fake();
        $now = time();
        $young = $this->order(Order::STATUS_PENDING, $now - 60);
        $justInside = $this->order(Order::STATUS_PENDING, $now - OrderHandleJob::PENDING_CANCEL_AFTER + 30);
        $expired = $this->order(Order::STATUS_PENDING, $now - OrderHandleJob::PENDING_CANCEL_AFTER - 1);
        $processing = $this->order(Order::STATUS_PROCESSING, $now - 5);
        $this->order(Order::STATUS_COMPLETED, $now - 86400);
        $this->order(Order::STATUS_CANCELLED, $now - 86400);
        $this->order(Order::STATUS_DISCOUNTED, $now - 86400);

        $this->artisan('check:order')->assertSuccessful();

        Queue::assertPushed(OrderHandleJob::class, 2);
        $pushed = [];
        Queue::assertPushed(OrderHandleJob::class, function (OrderHandleJob $job) use (&$pushed) {
            $pushed[] = (fn () => $this->tradeNo)->call($job);
            return true;
        });
        $this->assertEqualsCanonicalizing([$expired, $processing], $pushed);
        $this->assertNotContains($young, $pushed);
        $this->assertNotContains($justInside, $pushed);
        Queue::assertPushedOn('order_handle', OrderHandleJob::class);
    }

    #[Test]
    public function more_than_one_page_of_orders_is_dispatched_exactly_once_each(): void
    {
        Queue::fake();
        // 450 单、创建时间与 id 反向：旧实现 orderBy(created_at) + lazyById 会从第二页起重复 / 漏单
        $now = time();
        $expected = [];
        for ($i = 0; $i < 450; $i++) {
            $expected[] = $this->order(Order::STATUS_PROCESSING, $now - $i);
        }

        $this->artisan('check:order')->assertSuccessful();

        $pushed = [];
        Queue::assertPushed(OrderHandleJob::class, function (OrderHandleJob $job) use (&$pushed) {
            $pushed[] = (fn () => $this->tradeNo)->call($job);
            return true;
        });
        $this->assertCount(450, $pushed);
        $this->assertSame(450, count(array_unique($pushed)));
        $this->assertEqualsCanonicalizing($expected, $pushed);
    }

    #[Test]
    public function the_job_still_cancels_only_orders_past_the_same_threshold(): void
    {
        $now = time();
        $young = $this->order(Order::STATUS_PENDING, $now - 60);
        $expired = $this->order(Order::STATUS_PENDING, $now - OrderHandleJob::PENDING_CANCEL_AFTER - 1);

        OrderHandleJob::dispatchSync($young);
        OrderHandleJob::dispatchSync($expired);

        $this->assertSame(Order::STATUS_PENDING, (int) Order::where('trade_no', $young)->value('status'));
        $this->assertSame(Order::STATUS_CANCELLED, (int) Order::where('trade_no', $expired)->value('status'));
    }
}
