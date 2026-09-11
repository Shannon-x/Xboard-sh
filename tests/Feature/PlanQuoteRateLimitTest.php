<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 自选套餐报价接口的限流。
 *
 * 背景：/guest/plan/quote 与 /user/plan/quote 都是有 DB 读 + 计价成本的 POST，
 * 且 guest 端无鉴权。上线时这两条路由没有挂 throttle，而同仓库所有同类端点
 * （coupon-check / gift-card-check / ticket-attachment-* …）都有具名限流器，
 * 属于与自身规范不一致的缺口：任何人可无限调用，构成资源耗尽面。
 *
 * 阈值取 40/min（按用户，未登录退化为 IP）+ 120/min（按 IP 兜底），高于前端
 * 250ms 防抖下的正常配置频率，只拦脚本式高频调用。
 */
class PlanQuoteRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** 与 ConfigurablePlanTest 保持同一套可自选套餐，便于对照。 */
    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Configurable', 'capacity_limit' => null, 'group_id' => 17,
            'show' => true, 'sell' => true, 'renew' => true,
            'transfer_enable' => 100, 'device_limit' => 2, 'speed_limit' => 100,
            'reset_traffic_method' => 1,
            'prices' => ['monthly' => 3, 'reset_traffic' => 3],
            'customization' => [
                'transfer_enable' => ['max' => 2000, 'step' => 100, 'price_per_step' => 60],
                'device_limit' => ['max' => 10, 'step' => 1, 'price_per_step' => 50],
                'speed_limit' => ['max' => 1000, 'step' => 100, 'price_per_step' => 20],
            ],
        ]);
    }

    private function user(): User
    {
        return User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => Str::random(32),
            'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0,
        ]);
    }

    private function payload(Plan $plan): array
    {
        return [
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'options' => ['transfer_enable' => 500, 'device_limit' => 3, 'speed_limit' => 400],
        ];
    }

    private function quote(string $path, Plan $plan)
    {
        return $this->postJson($path, $this->payload($plan));
    }

    public function test_guest_quote_is_throttled_after_the_per_minute_budget(): void
    {
        $plan = $this->plan();

        for ($i = 0; $i < 40; $i++) {
            $this->quote('/api/v1/guest/plan/quote', $plan)->assertOk();
        }

        $this->quote('/api/v1/guest/plan/quote', $plan)->assertStatus(429);
    }

    public function test_user_quote_is_throttled_after_the_per_minute_budget(): void
    {
        $plan = $this->plan();
        Sanctum::actingAs($this->user());

        for ($i = 0; $i < 40; $i++) {
            $this->quote('/api/v1/user/plan/quote', $plan)->assertOk();
        }

        $this->quote('/api/v1/user/plan/quote', $plan)->assertStatus(429);
    }

    /**
     * 登录用户按 user 维度计数：一个账号打满不能牵连另一个账号。
     * 两人合计 41 次仍在 120/min 的 IP 兜底之内，所以第二个账号必须放行。
     */
    public function test_each_signed_in_user_has_an_independent_budget(): void
    {
        $plan = $this->plan();

        Sanctum::actingAs($this->user());
        for ($i = 0; $i < 40; $i++) {
            $this->quote('/api/v1/user/plan/quote', $plan)->assertOk();
        }
        $this->quote('/api/v1/user/plan/quote', $plan)->assertStatus(429);

        Sanctum::actingAs($this->user());
        $this->quote('/api/v1/user/plan/quote', $plan)->assertOk();
    }

    /**
     * 回归保护：限流不能影响正常的一次性报价，金额仍须是服务端算出的 650 分
     * （月付基础价 300 + 流量 4 步 × 60 + 设备 1 步 × 50 + 速度 3 步 × 20）。
     */
    public function test_a_normal_quote_is_not_affected(): void
    {
        $plan = $this->plan();

        $this->quote('/api/v1/guest/plan/quote', $plan)
            ->assertOk()
            ->assertJsonPath('data.amount', 650);
    }
}
