<?php

namespace Tests\Feature;

use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 验证 FeatureFlag 读取行为。
 *
 * 当前保留的 flag：
 *   - payment_amount_check : 默认 warn（仅观察、不拒收）
 *   - payment_gateway_bind : 默认 warn（回调网关与订单 payment_id 绑定，仅观察、不拒收）
 *   - payment_secret_hide  : 默认 false（前端未配合前不能开启）
 *
 * paid() 行锁、OrderHandleJob 事务、签名 hash_equals 等纯修复已直接写死在代码中，
 * 不再可通过 flag 控制，因此也不在此测试中。
 */
class FeatureFlagTest extends TestCase
{
    public function test_payment_amount_check_defaults_to_warn(): void
    {
        $this->assertSame('warn', FeatureFlag::mode('payment_amount_check'));
        $this->assertTrue(FeatureFlag::is('payment_amount_check', 'warn'));
        $this->assertFalse(FeatureFlag::is('payment_amount_check', 'enforce'));
        $this->assertFalse(FeatureFlag::enabled('payment_amount_check'));
    }

    public function test_payment_gateway_bind_defaults_to_warn(): void
    {
        $this->assertSame('warn', FeatureFlag::mode('payment_gateway_bind'));
        $this->assertTrue(FeatureFlag::is('payment_gateway_bind', 'warn'));
        $this->assertFalse(FeatureFlag::is('payment_gateway_bind', 'enforce'));
        $this->assertFalse(FeatureFlag::enabled('payment_gateway_bind'));
    }

    public function test_payment_secret_hide_defaults_to_old_behavior(): void
    {
        $this->assertFalse(FeatureFlag::enabled('payment_secret_hide'));
    }

    public function test_mode_accepts_three_levels(): void
    {
        Config::set('feature_flags.payment_amount_check', 'off');
        $this->assertSame('off', FeatureFlag::mode('payment_amount_check'));
        $this->assertFalse(FeatureFlag::enabled('payment_amount_check'));

        Config::set('feature_flags.payment_amount_check', 'warn');
        $this->assertSame('warn', FeatureFlag::mode('payment_amount_check'));
        $this->assertTrue(FeatureFlag::is('payment_amount_check', 'warn'));
        $this->assertFalse(FeatureFlag::enabled('payment_amount_check'));

        Config::set('feature_flags.payment_amount_check', 'enforce');
        $this->assertSame('enforce', FeatureFlag::mode('payment_amount_check'));
        $this->assertTrue(FeatureFlag::enabled('payment_amount_check'));
    }

    public function test_invalid_mode_falls_back_to_off(): void
    {
        Config::set('feature_flags.payment_amount_check', 'banana');
        $this->assertSame('off', FeatureFlag::mode('payment_amount_check'));
        $this->assertFalse(FeatureFlag::enabled('payment_amount_check'));
    }

    public function test_unknown_flag_falls_back_to_off(): void
    {
        $this->assertFalse(FeatureFlag::enabled('nonexistent_flag'));
        $this->assertSame('off', FeatureFlag::mode('nonexistent_flag'));
    }
}
