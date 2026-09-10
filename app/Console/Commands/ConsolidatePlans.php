<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\PlanCustomizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConsolidatePlans extends Command
{
    protected $signature = 'plan:consolidate {--source=* : Existing plan IDs} {--config= : JSON file containing the new plan definition} {--apply : Create the new plan as a hidden draft} {--publish : Show the new plan and hide source plans (requires --apply)}';
    protected $description = 'Preview or consolidate compatible plans without changing existing users or orders';

    public function handle(PlanCustomizationService $calculator): int
    {
        try {
            $ids = array_values(array_unique(array_map('intval', $this->option('source'))));
            if (count($ids) < 2 || !$this->option('config')) {
                throw new \RuntimeException('至少提供两个 --source ID 和一个 --config JSON 文件');
            }
            if ($this->option('publish') && !$this->option('apply')) {
                throw new \RuntimeException('--publish 必须与 --apply 一起使用');
            }
            $definition = json_decode(file_get_contents($this->option('config')), true, 512, JSON_THROW_ON_ERROR);
            $allowed = ['name', 'transfer_enable', 'device_limit', 'speed_limit', 'prices', 'customization', 'content', 'tags', 'capacity_limit'];
            if (!is_array($definition) || array_diff(array_keys($definition), $allowed)) {
                throw new \RuntimeException('配置包含不允许的字段');
            }
            if (empty($definition['name']) || empty($definition['customization'])) {
                throw new \RuntimeException('必须指定新套餐名称和完整自选规格配置');
            }
            return DB::transaction(function () use ($ids, $definition, $calculator) {
                $sources = Plan::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($sources->count() !== count($ids) || $sources->pluck('group_id')->unique()->count() !== 1
                    || $sources->pluck('reset_traffic_method')->uniqueStrict()->count() !== 1) {
                    throw new \RuntimeException('仅能合并全部存在、权限组和流量重置规则相同的套餐');
                }
                $billing = $sources->map(fn ($plan) => isset($plan->prices['onetime']) ? 'onetime' : 'recurring')->unique();
                if ($billing->count() !== 1 || ($billing->first() === 'onetime') !== isset($definition['prices']['onetime'])) {
                    throw new \RuntimeException('周期套餐与不限时流量包不能合并');
                }
                if ($sources->contains(fn ($plan) => $plan->capacity_limit !== null) && !array_key_exists('capacity_limit', $definition)) {
                    throw new \RuntimeException('源套餐存在席位限制，必须明确设置新套餐 capacity_limit');
                }
                $first = $sources->first();
                $plan = new Plan($definition + [
                    'group_id' => $first->group_id, 'reset_traffic_method' => $first->reset_traffic_method,
                    'sort' => $sources->min('sort'), 'show' => false, 'sell' => false, 'renew' => true,
                    'capacity_limit' => null,
                ]);
                $calculator->validateConfiguration($plan);
                $this->table(['ID', 'Name', 'GB', 'Devices', 'Mbps'], $sources->map(fn ($p) => [$p->id, $p->name, $p->transfer_enable, $p->device_limit, $p->speed_limit]));
                $this->line(json_encode($plan->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if (!$this->option('apply')) {
                    $this->info('仅预览；未修改套餐、用户或订单。使用 --apply 创建隐藏草稿；加 --publish 同时切换销售入口。');
                    return self::SUCCESS;
                }
                if (Plan::where('name', $plan->name)->where('group_id', $plan->group_id)->exists()) {
                    throw new \RuntimeException('同权限组已存在该名称的套餐，拒绝重复创建');
                }
                $plan->show = $plan->sell = (bool) $this->option('publish');
                $plan->saveOrFail();
                if ($this->option('publish')) {
                    Plan::whereIn('id', $ids)->update(['show' => false, 'sell' => false]);
                }
                $this->info('新套餐 ID: '.$plan->id.'；历史用户、订单和原续费开关保持原值。');
                return self::SUCCESS;
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
