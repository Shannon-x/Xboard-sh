<?php

namespace Tests\Feature;

use App\Models\BalanceLog;
use App\Models\CommissionLog;
use App\Models\CommissionWithdrawal;
use App\Models\User;
use App\Services\BalanceLedger;
use App\Services\Commission\EarningsSummary;
use App\Services\Commission\WithdrawalConfig;
use App\Support\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 提现汇率上浮（百分比 / 固定值）与邀请页「我的收益」汇总。
 */
class WithdrawRateMarkupTest extends TestCase
{
    use RefreshDatabase;

    private const TRON_ADDRESS = 'TN3W4H6rK2ce4vX9YnFQHwKENnHjoxb3m9';

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Cache::flush();

        $stub = new class extends Setting {
            public array $store = [];
            public function __construct()
            {
            }
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[strtolower($key)] ?? $default;
            }
            public function save(array $settings): bool
            {
                foreach ($settings as $k => $v) {
                    $this->store[strtolower($k)] = $v;
                }
                return true;
            }
            public function set(string $key, mixed $value = null): bool
            {
                return $this->save([$key => $value]);
            }
        };
        $stub->store = &$this->settings;
        $this->app->instance(Setting::class, $stub);

        $this->settings = [
            'commission_withdraw_limit' => 10,
            'commission_withdraw_rate_source' => 'auto',
            'commission_withdraw_usdt_rate' => 7.5,
        ];
    }

    /** 行情缓存焐热成 6.70（币安 C2C），不打真实接口 */
    private function warmMarket(float $rate = 6.70): void
    {
        Cache::put('commission:usdt_rate:CNY', ['rate' => $rate, 'source' => 'binance_p2p', 'fetched_at' => time()], 86400);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create($attrs + [
            'email' => 'mk-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 20000,
            'transfer_enable' => 0,
            'expired_at' => 0,
        ]);
    }

    public function test_percent_markup_raises_the_rate_and_hides_the_market_label(): void
    {
        $this->warmMarket();
        $this->settings['commission_withdraw_rate_markup_type'] = 'percent';
        $this->settings['commission_withdraw_rate_markup'] = 3;

        $config = WithdrawalConfig::fromSettings();
        $this->assertSame(6.901, $config->usdtRate(), '6.70 × 1.03');
        $this->assertSame(6.70, $config->rateSnapshot()['market_rate']);

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/user/withdraw/config')
            ->assertOk()
            ->assertJsonPath('data.usdt_rate', 6.901)
            // 上浮后的数字不是行情原价，不能再对用户标「币安 C2C 场外价」
            ->assertJsonPath('data.usdt_rate_source_label', '')
            ->assertJsonMissingPath('data.market_rate');
    }

    public function test_fixed_markup_adds_to_the_market_rate_and_lowers_the_usdt_paid(): void
    {
        $this->warmMarket();
        $this->settings['commission_withdraw_rate_markup_type'] = 'fixed';
        $this->settings['commission_withdraw_rate_markup'] = 0.2;

        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/user/withdraw/apply', [
            'amount' => 13800, // ¥138
            'chain' => 'usdt_trc20',
            'address' => self::TRON_ADDRESS,
        ])->assertOk()
            ->assertJsonPath('data.usdt_rate', '6.9000')
            // 138 / 6.9 = 20 USDT，扣 TRC20 通道费 1 → 19
            ->assertJsonPath('data.usdt_amount', '19.0000');
    }

    public function test_manual_rate_and_auto_fallback_are_used_as_is(): void
    {
        $this->settings['commission_withdraw_rate_markup'] = 5;

        // 自动但行情全挂（没有缓存且接口失败）→ 用兜底 7.5，不再上浮
        Http::fake(['*' => Http::response([], 500)]);
        $this->assertSame(7.5, WithdrawalConfig::fromSettings()->usdtRate());

        // 手动固定 → 原样
        $this->warmMarket();
        $this->settings['commission_withdraw_rate_source'] = 'manual';
        $this->settings['commission_withdraw_usdt_rate'] = 7.1;
        $this->assertSame(7.1, WithdrawalConfig::fromSettings()->usdtRate());
    }

    public function test_no_markup_keeps_the_old_behaviour(): void
    {
        $this->warmMarket();
        $config = WithdrawalConfig::fromSettings();
        $this->assertSame(6.70, $config->usdtRate());
        $this->assertFalse($config->rateSnapshot()['markup_applied']);
        $this->assertSame('币安 C2C 场外价', $config->toPublicArray()['usdt_rate_source_label']);
    }

    public function test_markup_is_normalised(): void
    {
        $this->assertSame(['percent', 0.0], WithdrawalConfig::normalizeMarkup('weird', -3));
        $this->assertSame(['percent', 50.0], WithdrawalConfig::normalizeMarkup('percent', 80));
        $this->assertSame(['fixed', 0.35], WithdrawalConfig::normalizeMarkup('FIXED', '0.35'));
        $this->assertSame(['percent', 0.0], WithdrawalConfig::normalizeMarkup('percent', 'abc'));
    }

    public function test_earnings_summary_splits_where_the_commission_went(): void
    {
        $now = CarbonImmutable::create(2026, 11, 11, 12, 0, 0);
        $user = $this->makeUser(['commission_balance' => 4000]);
        $buyer = $this->makeUser(['invite_user_id' => $user->id]);
        $log = function (int $amount, CarbonImmutable $at) use ($user, $buyer) {
            $row = CommissionLog::create([
                'invite_user_id' => $user->id, 'user_id' => $buyer->id, 'trade_no' => 'T' . Str::random(8),
                'order_amount' => $amount * 3, 'get_amount' => $amount,
            ]);
            CommissionLog::where('id', $row->id)->update(['created_at' => $at->getTimestamp()]);
        };
        $log(3000, $now->subDays(2));             // 本月
        $log(2000, $now->subMonthNoOverflow());   // 上月
        $log(5000, $now->subMonths(3));
        $log(9999, $now->subMonths(8));           // 超出 6 个月窗口，只进累计

        $base = ['currency' => 'CNY', 'chain_code' => 'usdt_trc20', 'chain_name' => 'USDT', 'address' => self::TRON_ADDRESS];
        CommissionWithdrawal::create($base + ['user_id' => $user->id, 'amount' => 10000, 'status' => CommissionWithdrawal::STATUS_COMPLETED]);
        CommissionWithdrawal::create($base + ['user_id' => $user->id, 'amount' => 1500, 'status' => CommissionWithdrawal::STATUS_PENDING]);
        CommissionWithdrawal::create($base + ['user_id' => $user->id, 'amount' => 700, 'status' => CommissionWithdrawal::STATUS_REJECTED]);
        BalanceLedger::record($user, BalanceLog::TYPE_COMMISSION_TRANSFER, 2500, ['ref_type' => 'commission']);

        $summary = EarningsSummary::forUser($user, 800, $now);

        $this->assertSame(19999, $summary['total']);
        $this->assertSame(10000, $summary['withdrawn']);
        $this->assertSame(1500, $summary['withdrawing'], '驳回的不算');
        $this->assertSame(2500, $summary['transferred']);
        $this->assertSame(4000, $summary['available']);
        $this->assertSame(800, $summary['pending']);
        $this->assertSame(1, $summary['withdraw_count']);
        $this->assertSame(3000, $summary['this_month']);
        $this->assertSame(2000, $summary['last_month']);
        $this->assertSame(
            ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'],
            array_column($summary['months'], 'month')
        );
        $this->assertSame([0, 0, 5000, 0, 2000, 3000], array_column($summary['months'], 'amount'));
    }

    public function test_invite_fetch_carries_the_earnings_block(): void
    {
        $user = $this->makeUser(['commission_balance' => 1234]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/user/invite/fetch')
            ->assertOk()
            ->assertJsonPath('data.earnings.available', 1234)
            ->assertJsonPath('data.earnings.total', 0)
            ->assertJsonCount(EarningsSummary::MONTHS, 'data.earnings.months');
    }
}
