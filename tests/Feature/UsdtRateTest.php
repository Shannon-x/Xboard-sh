<?php

namespace Tests\Feature;

use App\Services\Commission\UsdtRateService;
use App\Services\Commission\WithdrawalConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * USDT 实时汇率取数：provider 顺序、异常值过滤、失效降级。
 * 全程 Http::fake，不打真实行情接口。
 */
class UsdtRateTest extends TestCase
{
    private const CACHE_KEY = 'commission:usdt_rate:CNY';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** 币安 C2C 多档报价取中位数，避开挂在最前面的异常单 */
    public function test_cny_uses_binance_p2p_median(): void
    {
        Http::fake([
            'p2p.binance.com/*' => Http::response(['data' => [
                ['adv' => ['price' => '6.60']],
                ['adv' => ['price' => '6.66']],
                ['adv' => ['price' => '6.68']],
                ['adv' => ['price' => '6.70']],
                ['adv' => ['price' => '6.72']],
            ]]),
        ]);

        $snapshot = (new UsdtRateService())->snapshot('CNY');

        $this->assertSame(6.68, $snapshot['rate']);
        $this->assertSame('binance_p2p', $snapshot['source']);
        $this->assertTrue($snapshot['is_live']);
        $this->assertFalse($snapshot['is_stale']);
    }

    /** 明显离谱的返回值要丢掉，换下一家；否则一次接口改版就能把提现金额算爆 */
    public function test_insane_value_is_rejected_and_next_provider_wins(): void
    {
        Http::fake([
            'p2p.binance.com/*' => Http::response(['data' => [['adv' => ['price' => '0.0001']]]]),
            'www.okx.com/*' => Http::response(['code' => '0', 'data' => [['usdCny' => '6.72']]]),
        ]);

        $snapshot = (new UsdtRateService())->snapshot('CNY');

        $this->assertSame(6.72, $snapshot['rate']);
        $this->assertSame('okx', $snapshot['source']);
    }

    public function test_non_cny_currency_uses_coingecko(): void
    {
        Http::fake([
            'api.coingecko.com/*' => Http::response(['tether' => ['jpy' => 156.25]]),
        ]);

        $snapshot = (new UsdtRateService())->snapshot('JPY');

        $this->assertSame(156.25, $snapshot['rate']);
        $this->assertSame('coingecko', $snapshot['source']);
    }

    /** 行情站全挂时继续用上一次成功值，并标记为过期 */
    public function test_serves_stale_cache_when_every_provider_fails(): void
    {
        Cache::put(self::CACHE_KEY, [
            'rate' => 6.5,
            'source' => 'coingecko',
            'fetched_at' => time() - (UsdtRateService::FRESH_TTL + 60),
        ], UsdtRateService::STALE_TTL);
        Http::fake(['*' => Http::response([], 503)]);

        $snapshot = (new UsdtRateService())->snapshot('CNY');

        $this->assertSame(6.5, $snapshot['rate']);
        $this->assertTrue($snapshot['is_stale']);
        $this->assertSame('coingecko', $snapshot['source']);
    }

    /** 连旧值都没有时如实返回「取不到」，交给上层回退到后台兜底值 */
    public function test_returns_empty_when_no_cache_and_all_fail(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $snapshot = (new UsdtRateService())->snapshot('CNY');

        $this->assertNull($snapshot['rate']);
        $this->assertSame('none', $snapshot['source']);
        $this->assertFalse($snapshot['is_live']);
    }

    /** 新鲜缓存直接返回，不再发请求 */
    public function test_fresh_cache_short_circuits_http(): void
    {
        Cache::put(self::CACHE_KEY, ['rate' => 6.9, 'source' => 'okx', 'fetched_at' => time()], UsdtRateService::STALE_TTL);
        Http::fake(['*' => Http::response([], 500)]);

        $snapshot = (new UsdtRateService())->snapshot('CNY');

        $this->assertSame(6.9, $snapshot['rate']);
        Http::assertNothingSent();
    }

    /** 整轮失败后进入冷却，避免每个请求都去撞一遍超时 */
    public function test_failure_cooldown_prevents_repeated_requests(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $service = new UsdtRateService();

        $service->snapshot('CNY');
        $sentAfterFirst = count(Http::recorded());
        $service->snapshot('CNY');

        $this->assertSame($sentAfterFirst, count(Http::recorded()), '冷却期内不应再发请求');
        $this->assertGreaterThan(0, $sentAfterFirst);
    }

    public function test_sanity_band_rejects_out_of_range_values(): void
    {
        $this->assertTrue(UsdtRateService::isSane('CNY', 6.7));
        $this->assertFalse(UsdtRateService::isSane('CNY', 0.0001));
        $this->assertFalse(UsdtRateService::isSane('CNY', 999.0));
        $this->assertFalse(UsdtRateService::isSane('USD', 0.0));
        // 没有区间数据的币种只要求是正数
        $this->assertTrue(UsdtRateService::isSane('XYZ', 1234.5));
    }

    public function test_median_handles_even_and_odd_counts(): void
    {
        $this->assertSame(2.0, UsdtRateService::median([1.0, 2.0, 3.0]));
        $this->assertSame(2.5, UsdtRateService::median([1.0, 2.0, 3.0, 4.0]));
        $this->assertNull(UsdtRateService::median([]));
    }

    /** 网络目录：老配置只存了 network 文本，也能自动补出地址格式 / 通道费 / 浏览器链接 */
    public function test_network_catalog_backfills_legacy_chain_config(): void
    {
        $chains = WithdrawalConfig::normalizeChains([
            ['name' => 'USDT', 'network' => 'TRC20 (Tron)'],
            ['name' => 'USDT', 'network' => 'Solana (SPL)'],
        ]);

        $this->assertSame('usdt_trc20', $chains[0]['code'], 'code 必须与历史一致，否则老提现记录对不上链');
        $this->assertSame('tron', $chains[0]['preset']);
        $this->assertSame('^T[1-9A-HJ-NP-Za-km-z]{33}$', $chains[0]['pattern']);
        $this->assertSame(1.0, $chains[0]['fee']);
        $this->assertStringContainsString('tronscan.org', $chains[0]['explorer_tx']);

        $this->assertSame('solana', $chains[1]['preset']);
        $this->assertStringContainsString('solscan.io', $chains[1]['explorer_tx']);
    }

    /** 管理员显式填的值永远压过目录默认值 */
    public function test_admin_overrides_win_over_catalog(): void
    {
        $chains = WithdrawalConfig::normalizeChains([[
            'name' => 'USDT',
            'network_key' => 'trc20',
            'fee' => 0.25,
            'explorer_tx' => 'https://example.com/{txid}',
        ]]);

        $this->assertSame(0.25, $chains[0]['fee']);
        $this->assertSame('https://example.com/{txid}', $chains[0]['explorer_tx']);
        $this->assertSame('tron', $chains[0]['preset'], '没填的字段仍从目录补齐');
    }

    /** 报价 = 毛额 - 通道费，且不会算成负数 */
    public function test_quote_deducts_channel_fee(): void
    {
        $config = new WithdrawalConfig(
            enable: true,
            minCents: 0,
            maxCents: 0,
            chains: [],
            rateSource: WithdrawalConfig::RATE_SOURCE_MANUAL,
            fallbackRate: 7.2,
            requireQrcode: false,
            thanks: '',
            currency: 'CNY',
            currencySymbol: '¥',
        );
        $chain = WithdrawalConfig::normalizeChains([['name' => 'USDT', 'network_key' => 'erc20']])[0];

        $quote = $config->quote(10000, $chain);
        $this->assertSame('13.8889', $quote['gross']);
        $this->assertSame('5.0000', $quote['fee']);
        $this->assertSame('8.8889', $quote['net']);

        // 通道费高于毛额时到账为 0，不会出现负数
        $this->assertSame('0.0000', $config->quote(100, $chain)['net']);
    }
}
