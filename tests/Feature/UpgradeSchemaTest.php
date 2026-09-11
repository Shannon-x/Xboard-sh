<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpgradeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_migration_can_resume_without_losing_existing_data(): void
    {
        $plan = Plan::create(['name' => 'Keep me', 'group_id' => 1, 'transfer_enable' => 100,
            'customization' => ['transfer_enable' => ['mode' => 'fixed']]]);
        Schema::table('v2_order', fn ($table) => $table->dropColumn('plan_snapshot'));
        Schema::table('v2_user', fn ($table) => $table->dropColumn('plan_options'));
        $this->artisan('xboard:check-upgrade')->assertFailed();
        $migration = require database_path('migrations/2026_09_10_000001_add_configurable_plan_options.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('v2_order', 'plan_snapshot'));
        $this->assertTrue(Schema::hasColumn('v2_user', 'plan_options'));
        $this->assertSame($plan->customization, $plan->fresh()->customization);
        $this->artisan('xboard:check-upgrade')->assertSuccessful();
        $this->artisan('xboard:check-upgrade --require-legacy-rollback')->assertFailed();
    }

    public function test_fixed_plan_database_passes_read_only_rollback_gate(): void
    {
        $this->artisan('xboard:check-upgrade --require-legacy-rollback')->assertSuccessful();
    }

    public function test_gateway_identifier_upgrade_preserves_rows_and_refuses_destructive_shrink(): void
    {
        $payment = \App\Models\Payment::create(['uuid' => 'compatibility-gateway',
            'name' => 'Recurring', 'payment' => 'StripeSubscription', 'config' => [], 'enable' => true]);
        $migration = require database_path('migrations/2026_09_11_000002_widen_payment_gateway_identifier.php');
        $migration->up();
        $this->assertSame('StripeSubscription', $payment->fresh()->payment);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without truncating');
        $migration->down();
    }
}
