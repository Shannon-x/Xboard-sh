<?php

namespace App\Services\Commission;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 实时 USDT 汇率（1 USDT 折合多少站点法币）。
 *
 * 以前这个数字是管理员在后台手填的，一旦行情变了就没人记得改——2026-09 线上还挂着 7.2，
 * 而当时人民币场外价已经是 6.67，等于每笔提现都多付用户 8%。现在改为按需从公开行情接口取，
 * 后台那个数字降级成「接口全挂时的兜底」。
 *
 * 取数策略：
 * - 命中 FRESH_TTL 内的缓存就直接返回，不发请求；
 * - 过期后依次尝试 provider，第一个通过合理性校验的即采用；
 * - 全部失败时继续用最后一次成功值（最长 STALE_TTL），并进入 FAIL_COOLDOWN 冷却，
 *   避免每个请求都去撞一遍超时；
 * - 连旧值都没有，交给调用方回退到后台兜底值。
 *
 * 请求路径上最坏耗时被 DEADLINE 限制住，宁可这次拿不到也不能把用户的提现页面卡住；
 * 常态下由 `commission:refresh-usdt-rate` 定时任务提前把缓存焐热。
 */
final class UsdtRateService
{
    private const CACHE_PREFIX = 'commission:usdt_rate:';
    private const COOLDOWN_PREFIX = 'commission:usdt_rate_cooldown:';

    /** 缓存多久算新鲜（秒）——行情波动对提现估算而言 10 分钟足够 */
    public const FRESH_TTL = 600;
    /** 接口全挂时，最多拿多久以前的旧值顶上（秒） */
    public const STALE_TTL = 86400;
    /** 一轮全部失败后的冷却时间（秒） */
    public const FAIL_COOLDOWN = 120;
    /** 单次刷新的总时间预算（秒），超了就放弃剩下的 provider */
    public const DEADLINE = 6.0;

    /** 单个 provider 的连接 / 总超时（秒） */
    private const CONNECT_TIMEOUT = 2;
    private const TIMEOUT = 4;

    /**
     * 常见法币的合理区间（1 USDT 兑多少）。落在区间外的一律当脏数据丢弃，
     * 防止接口改版 / 返回错字段时把汇率写成 0.0001 这种能把提现算爆的值。
     */
    public const SANITY = [
        'CNY' => [4.0, 12.0],
        'USD' => [0.8, 1.2],
        'EUR' => [0.6, 1.3],
        'GBP' => [0.5, 1.2],
        'JPY' => [80.0, 260.0],
        'HKD' => [5.0, 11.0],
        'TWD' => [20.0, 45.0],
        'KRW' => [800.0, 2200.0],
        'SGD' => [0.9, 2.0],
        'AUD' => [0.9, 2.2],
        'CAD' => [0.9, 2.2],
        'RUB' => [40.0, 200.0],
        'INR' => [50.0, 130.0],
        'BRL' => [3.0, 10.0],
        'TRY' => [10.0, 90.0],
        'MYR' => [3.0, 7.0],
        'THB' => [25.0, 45.0],
        'VND' => [15000.0, 35000.0],
        'PHP' => [40.0, 75.0],
        'IDR' => [10000.0, 25000.0],
    ];

    /** 最近一轮取数失败的原因，供后台「刷新」按钮回显——服务器缺 CA 证书 / 出不了网时不该只显示「未取到」 */
    private ?string $lastError = null;

    public const SOURCE_LABELS = [
        'binance_p2p' => '币安 C2C 场外价',
        'okx' => 'OKX',
        'coingecko' => 'CoinGecko',
        'coinbase' => 'Coinbase',
        'manual' => '后台兜底汇率',
        'none' => '未取到',
    ];

    /**
     * 取一份汇率快照。
     *
     * @param  string $currency 站点法币代码，如 CNY
     * @param  bool   $force    true 时忽略缓存与冷却，强制打接口（后台「刷新」按钮用）
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    public function snapshot(string $currency, bool $force = false): array
    {
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $cacheKey = self::CACHE_PREFIX . $currency;
        /** @var array{rate: float, source: string, fetched_at: int}|null $cached */
        $cached = Cache::get($cacheKey);

        $now = time();
        if (!$force && is_array($cached) && isset($cached['rate'], $cached['fetched_at'])) {
            if ($now - (int) $cached['fetched_at'] < self::FRESH_TTL) {
                return $this->present($cached, false);
            }
        }

        // 刚整轮失败过就别再撞了，直接用旧值（若有）
        if (!$force && Cache::get(self::COOLDOWN_PREFIX . $currency)) {
            return $this->staleOrEmpty($cached, $now);
        }

        $fetched = $this->fetch($currency);
        if ($fetched !== null) {
            Cache::put($cacheKey, $fetched, self::STALE_TTL);
            return $this->present($fetched, false);
        }

        Cache::put(self::COOLDOWN_PREFIX . $currency, 1, self::FAIL_COOLDOWN);
        return $this->staleOrEmpty($cached, $now);
    }

    /**
     * 只读缓存，不发任何请求。用户端高频接口（提现配置）走这个，
     * 缓存空时才由 snapshot() 兜一次——保证行情站点抽风时页面依旧秒开。
     *
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    public function cached(string $currency): array
    {
        $currency = strtoupper(trim($currency)) ?: 'CNY';
        $cached = Cache::get(self::CACHE_PREFIX . $currency);
        if (is_array($cached) && isset($cached['rate'], $cached['fetched_at'])) {
            return $this->present($cached, time() - (int) $cached['fetched_at'] >= self::FRESH_TTL);
        }
        return $this->snapshot($currency);
    }

    /**
     * 依次尝试各 provider，返回第一个通过校验的结果。
     *
     * @return array{rate: float, source: string, fetched_at: int}|null
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function fetch(string $currency): ?array
    {
        $startedAt = microtime(true);
        $this->lastError = null;
        $errors = [];
        foreach ($this->providerOrder($currency) as $provider) {
            if (microtime(true) - $startedAt > self::DEADLINE) {
                break;
            }
            try {
                $rate = match ($provider) {
                    'binance_p2p' => $this->fromBinanceP2p($currency),
                    'okx' => $this->fromOkx($currency),
                    'coingecko' => $this->fromCoinGecko($currency),
                    'coinbase' => $this->fromCoinbase($currency),
                    default => null,
                };
            } catch (\Throwable $e) {
                // 行情站点挂了不该影响提现流程，记一条就换下一家
                Log::debug('[usdt-rate] provider failed', ['provider' => $provider, 'error' => $e->getMessage()]);
                $errors[] = $provider . ': ' . $e->getMessage();
                continue;
            }
            if ($rate !== null && self::isSane($currency, $rate)) {
                return ['rate' => $rate, 'source' => $provider, 'fetched_at' => time()];
            }
            $errors[] = $provider . ': ' . ($rate === null ? '无有效返回' : "返回值 {$rate} 不在合理区间");
        }
        $this->lastError = $errors ? mb_substr(implode('；', $errors), 0, 500) : null;
        return null;
    }

    /**
     * CNY 优先用币安 C2C 场外价——那才是用户实际能换到的价；其余法币走通用行情站。
     *
     * @return list<string>
     */
    private function providerOrder(string $currency): array
    {
        if ($currency === 'CNY') {
            return ['binance_p2p', 'okx', 'coingecko', 'coinbase'];
        }
        return ['coingecko', 'coinbase', 'binance_p2p'];
    }

    /**
     * 币安 C2C：取前若干条卖出广告报价的中位数，避开挂在最前面的异常低价单。
     */
    private function fromBinanceP2p(string $currency): ?float
    {
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->post('https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search', [
                'asset' => 'USDT',
                'fiat' => $currency,
                'tradeType' => 'SELL',
                'page' => 1,
                'rows' => 10,
                'payTypes' => [],
                'publisherType' => null,
            ]);
        if (!$response->successful()) {
            return null;
        }
        $prices = [];
        foreach ((array) $response->json('data', []) as $row) {
            $price = $row['adv']['price'] ?? null;
            if (is_numeric($price)) {
                $prices[] = (float) $price;
            }
        }
        return self::median($prices);
    }

    /**
     * OKX 只提供 USD/CNY 参考汇率；USDT 常年锚定 1 USD，用作 CNY 的备胎足够。
     */
    private function fromOkx(string $currency): ?float
    {
        if ($currency !== 'CNY') {
            return null;
        }
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->get('https://www.okx.com/api/v5/market/exchange-rate');
        if (!$response->successful()) {
            return null;
        }
        $value = $response->json('data.0.usdCny');
        return is_numeric($value) ? (float) $value : null;
    }

    private function fromCoinGecko(string $currency): ?float
    {
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->get('https://api.coingecko.com/api/v3/simple/price', [
                'ids' => 'tether',
                'vs_currencies' => strtolower($currency),
            ]);
        if (!$response->successful()) {
            return null;
        }
        $value = $response->json('tether.' . strtolower($currency));
        return is_numeric($value) ? (float) $value : null;
    }

    private function fromCoinbase(string $currency): ?float
    {
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->get('https://api.coinbase.com/v2/exchange-rates', ['currency' => 'USDT']);
        if (!$response->successful()) {
            return null;
        }
        $value = $response->json('data.rates.' . $currency);
        return is_numeric($value) ? (float) $value : null;
    }

    /** 没有区间数据的小币种只要求是正数 */
    public static function isSane(string $currency, float $rate): bool
    {
        if (!is_finite($rate) || $rate <= 0) {
            return false;
        }
        $band = self::SANITY[strtoupper($currency)] ?? null;
        if ($band === null) {
            return true;
        }
        return $rate >= $band[0] && $rate <= $band[1];
    }

    /**
     * @param  list<float> $values
     */
    public static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  array{rate: float, source: string, fetched_at: int} $entry
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    private function present(array $entry, bool $stale): array
    {
        return [
            'rate' => (float) $entry['rate'],
            'source' => (string) $entry['source'],
            'source_label' => self::SOURCE_LABELS[$entry['source']] ?? (string) $entry['source'],
            'fetched_at' => (int) $entry['fetched_at'],
            'is_live' => true,
            'is_stale' => $stale,
        ];
    }

    /**
     * @param  mixed $cached
     * @return array{rate: float|null, source: string, source_label: string, fetched_at: int|null, is_live: bool, is_stale: bool}
     */
    private function staleOrEmpty(mixed $cached, int $now): array
    {
        if (is_array($cached) && isset($cached['rate'], $cached['fetched_at'])
            && $now - (int) $cached['fetched_at'] < self::STALE_TTL
        ) {
            return $this->present($cached, true);
        }
        return [
            'rate' => null,
            'source' => 'none',
            'source_label' => self::SOURCE_LABELS['none'],
            'fetched_at' => null,
            'is_live' => false,
            'is_stale' => false,
        ];
    }
}
