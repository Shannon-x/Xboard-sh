<?php


namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\Payg\PaygService;
use App\Support\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    private const PRICE_MULTIPLIER = 100;

    /**
     * customization.addon_groups 以权限组 id 为键。JsonResource::removeMissingValues()
     * 看到某一层数组全是数字键就会 array_values() 重排，把 {"2":…,"3":…} 变成 [{…},{…}]，
     * 前端就再也对不回组 id。preserveKeys 也会传递给外层资源集合，因此套餐列表
     * 在 PlanService::getAvailablePlans() 过滤后必须 values()，保证仍返回 JSON 数组。
     */
    public $preserveKeys = true;

    /**
     * 套餐列表 / 单查接口把批量算好的剩余席位（PlanCapacity::remaining）和本人在等的到货提醒放进请求属性，
     * 这里按 plan_id 取，避免每个套餐各查一次。请求属性跟着请求走，Octane 下不会串到下一个请求。
     * 没放（订单详情、礼品卡里内嵌的套餐）就不输出 stock / restock_subscribed。
     */
    public const STOCK_ATTRIBUTE = 'plan_resource.remaining';
    public const RESTOCK_ATTRIBUTE = 'plan_resource.restock_plan_ids';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customizer = app(\App\Services\PlanCustomizationService::class);
        $customization = $customizer->isEnabled($this->resource) ? $this->resource['customization'] : null;
        if ($customization !== null) {
            // 加购规则的原始配置（含模式 / 分单价）不下发；用户端看解析后的 traffic_topup 顶层键。
            unset($customization[\App\Services\PlanCustomizationService::TOPUP_KEY]);
        }
        if ($customization !== null && $customizer->hasAddonConfig($this->resource)) {
            // 用户端没有分组接口：把组名与节点数内嵌进来，未购买者也只看得到「这一组有几个节点」，
            // 不暴露任何具体节点名称 / 地址。
            $customization[\App\Services\PlanCustomizationService::ADDON_KEY] = $customizer->addonGroupsForDisplay($this->resource, $request->user());
        }
        // 目录前缀标签（carrier: / segment: / family: / batch:）只给新前端：从 tags 里拿掉、解析成 catalog
        $catalog = PlanCatalog::split($this->resource['tags']);
        $remaining = $request->attributes->get(self::STOCK_ATTRIBUTE);
        $restock = $request->attributes->get(self::RESTOCK_ATTRIBUTE);
        $planId = (int) $this->resource['id'];
        return [
            'id' => $this->resource['id'],
            'group_id' => $this->resource['group_id'],
            'name' => $this->resource['name'],
            'tags' => $catalog['tags'],
            'catalog' => $catalog['catalog'],
            // 纯新增键：只说有货 / 售罄，席位数不出后台
            ...(is_array($remaining) ? ['stock' => ($remaining[$planId] ?? null) === 0 ? 'sold_out' : 'available'] : []),
            ...(is_array($restock) ? ['restock_subscribed' => in_array($planId, $restock, true)] : []),
            // 纯新增键：上网按量套餐的单价、最低充值、保底、赠送、自动补充；其余套餐为 null
            'payg' => app(PaygService::class)->planPayload($this->resource, $request->user()),
            'content' => $this->formatContent(),
            'content_template' => $customization
                ? str_replace('{{reset_method}}', $this->getResetMethodText(), $this->resource['content'] ?? '') : null,
            'customization' => $customization,
            // 纯新增键：该套餐是否可按 GB 加购流量及单价（分/GB）。旧前端不读；未开放为 null。
            'traffic_topup' => ($topup = $customizer->topupRule($this->resource)) ? [
                'price_per_gb' => $topup['price_per_gb'], 'min_gb' => $topup['min_gb'], 'max_gb' => $topup['max_gb'],
            ] : null,
            ...$this->getPeriodPrices(),
            // 席位上限不给用户看（有货 / 售罄见 stock）。键保留、恒为 null：旧前端按「不限」处理
            'capacity_limit' => null,
            'transfer_enable' => $this->resource['transfer_enable'],
            'speed_limit' => $this->resource['speed_limit'],
            'device_limit' => $this->resource['device_limit'],
            'show' => (bool) $this->resource['show'],
            'sell' => (bool) $this->resource['sell'],
            'renew' => (bool) $this->resource['renew'],
            'reset_traffic_method' => $this->resource['reset_traffic_method'],
            'sort' => $this->resource['sort'],
            'created_at' => $this->resource['created_at'],
            'updated_at' => $this->resource['updated_at']
        ];
    }

    /**
     * Get transformed period prices using Plan mapping
     *
     * @return array<string, float|null>
     */
    protected function getPeriodPrices(): array
    {
        return collect(Plan::LEGACY_PERIOD_MAPPING)
            ->mapWithKeys(function (string $newPeriod, string $legacyPeriod): array {
                $price = $this->resource['prices'][$newPeriod] ?? null;
                return [
                    $legacyPeriod => $price !== null
                        ? (float) $price * self::PRICE_MULTIPLIER
                        : null
                ];
            })
            ->all();
    }

    /**
     * Format content with template variables
     *
     * @return string
     */
    protected function formatContent(): string
    {
        $content = $this->resource['content'] ?? '';
        
        $replacements = [
            '{{transfer}}' => $this->resource['transfer_enable'],
            '{{speed}}' => $this->resource['speed_limit'] === NULL ? __('No Limit') : $this->resource['speed_limit'],
            '{{devices}}' => $this->resource['device_limit'] === NULL ? __('No Limit') : $this->resource['device_limit'],
            '{{reset_method}}' => $this->getResetMethodText(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    /**
     * Get reset method text
     *
     * @return string
     */
    protected function getResetMethodText(): string
    {
        $method = $this->resource['reset_traffic_method'];
        
        if ($method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $method = admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }
        return match ($method) {
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => __('First Day of Month'),
            Plan::RESET_TRAFFIC_MONTHLY => __('Monthly'),
            Plan::RESET_TRAFFIC_NEVER => __('Never'),
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => __('First Day of Year'),
            Plan::RESET_TRAFFIC_YEARLY => __('Yearly'),
            default => __('Monthly')
        };
    }
}
