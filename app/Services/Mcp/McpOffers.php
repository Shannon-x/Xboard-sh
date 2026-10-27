<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Notice;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Mcp\McpPresenter as P;
use Illuminate\Support\Collection;

/**
 * 给 AI 看的「现在有什么活动」：在显示中的公告，以及公告里写出来的优惠码。
 *
 * 优惠码只认公告里出现过的：后台的「启用」只表示这张券能用，不表示可以公开，
 * 站长私下发给某个渠道或某位客户的码不能被 AI 拿去到处推荐。逢年过节照常建券、发公告，
 * AI 就自动知道；公告下架或券过期，AI 就不再提。
 */
final class McpOffers
{
    private const NOTICE_LIMIT = 10;

    /** @return Collection<int, Notice> */
    public static function notices(): Collection
    {
        return Notice::where('show', true)->orderBy('sort')->orderByDesc('id')->limit(self::NOTICE_LIMIT)
            ->get(['title', 'content', 'created_at']);
    }

    /** 公告里提到、且此刻能用的优惠码 @return Collection<int, Coupon> */
    public static function announcedCoupons(?Collection $notices = null): Collection
    {
        $text = mb_strtolower(($notices ?? self::notices())
            ->map(fn (Notice $n) => $n->title . "\n" . strip_tags((string) $n->content))->implode("\n"));
        if (trim($text) === '') {
            return collect();
        }
        $now = time();
        return Coupon::where('show', true)
            ->where('started_at', '<=', $now)->where('ended_at', '>=', $now)
            ->where(fn ($q) => $q->whereNull('limit_use')->orWhere('limit_use', '>', 0))
            ->orderBy('ended_at')->limit(50)->get()
            // 整词匹配，免得短码误中正文里的普通单词
            ->filter(fn (Coupon $c) => $c->code !== '' && preg_match('/(?<![a-z0-9])' . preg_quote(mb_strtolower($c->code), '/') . '(?![a-z0-9])/u', $text) === 1)
            ->values();
    }

    public static function view(User $user): array
    {
        $notices = self::notices();
        $plans = Plan::pluck('name', 'id');
        return [
            'coupons' => self::announcedCoupons($notices)->map(function (Coupon $c) use ($user, $plans) {
                $usable = null;
                if ($c->limit_use_with_user !== null) {
                    $service = new CouponService($c->code);
                    $service->setUserId($user->id);
                    $usable = $service->checkLimitUseWithUser();
                }
                return [
                    'code' => (string) $c->code,
                    'name' => (string) $c->name,
                    'discount' => self::discountText($c),
                    'plans' => $c->limit_plan_ids ? collect($c->limit_plan_ids)->map(fn ($id) => $plans[(int) $id] ?? null)->filter()->values()->all() : '全部套餐',
                    'periods' => $c->limit_period ? array_map([P::class, 'periodName'], $c->limit_period) : '全部周期',
                    'valid_until' => P::time((int) $c->ended_at),
                    'limited_quantity' => $c->limit_use !== null,
                    'customer_can_still_use' => $usable ?? true,
                ];
            })->all(),
            'notices' => $notices->take(5)->map(fn (Notice $n) => [
                'title' => (string) $n->title,
                'date' => P::date($n->created_at ? (int) $n->getRawOriginal('created_at') : null),
                'content' => P::plain($n->content, 500),
            ])->values()->all(),
        ];
    }

    /**
     * 报价时估一下这张券能减多少（不占用名额）。
     * @return array{discount: int, message: ?string}
     */
    public static function estimate(string $code, User $user, Plan $plan, string $period, int $payable): array
    {
        $service = new CouponService($code);
        $service->setUserId($user->id);
        $service->setPlanId($plan->id);
        $service->setPeriod($period);
        try {
            $service->check();
        } catch (ApiException $e) {
            return ['discount' => 0, 'message' => $e->getMessage()];
        }
        $coupon = $service->getCoupon();
        $discount = (int) $coupon->type === 1
            ? (int) $coupon->value
            : (int) round($payable * max(0, min(100, (int) $coupon->value)) / 100);
        return ['discount' => max(0, min($payable, $discount)), 'message' => null];
    }

    private static function discountText(Coupon $c): string
    {
        if ((int) $c->type === 1) {
            return '立减 ¥' . P::yuan((int) $c->value);
        }
        $off = max(0, min(100, (int) $c->value));
        $rest = 100 - $off;
        return $rest % 10 === 0 ? ($rest / 10) . ' 折（减 ' . $off . '%）' : $rest . ' 折（减 ' . $off . '%）';
    }
}
