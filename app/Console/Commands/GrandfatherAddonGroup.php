<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\NodeSyncService;
use App\Services\PlanCustomizationService;
use Illuminate\Console\Command;

/**
 * 把某个权限组「老带新」地补进现有订阅者的已购增值组。
 *
 * 场景：某类节点（例如 10x 专线）此前对所有人开放，现在要改成按增值组加价出售。
 * 直接把节点挪进新权限组，全部老用户当场断连；直接设成套餐「包含」，则永远免费。
 * 这条命令走第三条路：把该组写进每个在订用户的 `plan_options.addon_groups`，
 * 效果等同于「他们上一周期已经买过这项增值服务」——
 *   · 本周期照常能用，不断连；
 *   · 下次续费时报价自动含这项加价，想留着就得多付钱（新版前端里也可以取消勾选，不付就不再有）。
 *
 * 执行顺序很重要（命令本身只负责第 3 步）：
 *   1. 新建权限组，把目标节点**追加**进该组（先别摘掉原组，此时可见性不变）；
 *   2. 在套餐编辑器里把该组配成「可选购」并定价 —— 必须先做，否则第 3 步写进去的选择
 *      在续费时会被 validateSelection 判成「不在可售选项内」，用户直接续不了费；
 *   3. 跑本命令补授权；
 *   4. 把目标节点的原权限组摘掉 —— 从这一刻起只有被补授权的人和新买的人看得到它们。
 *
 * 幂等：已经持有该组的用户会被跳过，可以反复跑。`--revert` 反向移除。
 */
class GrandfatherAddonGroup extends Command
{
    protected $signature = 'xboard:grandfather-addon-group
        {group : 要补授权的权限组 ID}
        {--plan=* : 限定套餐 ID；默认处理所有把该组配成「可选购」的套餐}
        {--include-expired : 连已过期的订阅一并处理（默认只处理未过期的）}
        {--allow-mismatched : 规格与套餐不符的用户也一并处理（默认跳过，见下方说明）}
        {--revert : 反向操作：把该组从用户已购增值组里移除}
        {--dry-run : 只统计不写库}';

    protected $description = '把某权限组补进现有订阅者的已购增值组，使其在下一次续费时按增值组计价';

    private const BYTES_PER_GB = 1073741824;

    public function handle(): int
    {
        $customizer = app(PlanCustomizationService::class);
        $groupId = (int) $this->argument('group');
        $group = ServerGroup::find($groupId);
        if (!$group) {
            $this->error("权限组 #{$groupId} 不存在。");
            return self::FAILURE;
        }
        $revert = (bool) $this->option('revert');
        $dryRun = (bool) $this->option('dry-run');

        $plans = $this->resolvePlans($customizer, $groupId, $revert);
        if ($plans === null) {
            return self::FAILURE;
        }
        if ($plans->isEmpty()) {
            $this->error($revert
                ? '没有找到需要处理的套餐。用 --plan 指定套餐 ID。'
                : "没有任何套餐把权限组「{$group->name}」配成「可选购」。请先在套餐编辑器里配置并定价，再跑本命令。");
            return self::FAILURE;
        }

        $this->line(($revert ? '撤销' : '补授权') . "：权限组 #{$groupId}「{$group->name}」"
            . ($dryRun ? '（演练，不写库）' : ''));

        $totals = ['changed' => 0, 'already' => 0, 'mismatched' => 0, 'invalid' => 0];
        $mismatchedIds = [];
        $invalidIds = [];

        foreach ($plans as $plan) {
            $stats = $this->processPlan($customizer, $plan, $groupId, $revert, $dryRun, $mismatchedIds, $invalidIds);
            foreach ($stats as $key => $value) {
                $totals[$key] += $value;
            }
            $this->line(sprintf(
                '  套餐 #%d「%s」：%s %d · 本就%s %d · 规格不符跳过 %d · 选择非法跳过 %d',
                $plan->id, $plan->name,
                $revert ? '撤销' : '授予', $stats['changed'],
                $revert ? '没有' : '持有', $stats['already'],
                $stats['mismatched'], $stats['invalid'],
            ));
        }

        $this->line(sprintf(
            '合计：%s %d · 无需处理 %d · 跳过 %d',
            $revert ? '撤销' : '授予', $totals['changed'],
            $totals['already'], $totals['mismatched'] + $totals['invalid'],
        ));

        $this->reportMismatched($mismatchedIds);
        if ($invalidIds !== []) {
            $this->warn('以下用户的现有规格选择不被套餐当前配置接受，已跳过（他们本来就续不了费，与本次操作无关）：'
                . implode(', ', array_slice($invalidIds, 0, 20)) . (count($invalidIds) > 20 ? ' …' : ''));
        }

        if ($dryRun || $totals['changed'] === 0) {
            return self::SUCCESS;
        }

        // 批量写库时关掉模型事件（否则每个用户一个 NodeUserSyncJob，上万条任务压垮队列）。
        // 改为在这里一次性失效缓存 + 按组整体推一次名单；没推到的节点也会在下一次 pull（≤60s）拿到。
        PlanCustomizationService::forgetAddonCaches();
        try {
            NodeSyncService::notifyUsersUpdatedByGroup($groupId);
        } catch (\Throwable $e) {
            $this->warn('按组推送节点名单失败（不影响授权结果，节点下次拉取即生效）：' . $e->getMessage());
        }
        $this->info('完成。' . ($revert ? '' : '记得把目标节点的原权限组摘掉，该组节点才会变成增值专属。'));

        return self::SUCCESS;
    }

    /** 目标套餐：默认所有把该组配成「可选购」的套餐；--plan 时校验其确实在售该组。 */
    private function resolvePlans(PlanCustomizationService $customizer, int $groupId, bool $revert)
    {
        $explicit = array_values(array_filter(array_map('intval', (array) $this->option('plan'))));
        $query = $explicit !== [] ? Plan::whereIn('id', $explicit) : Plan::whereNotNull('customization');
        $plans = $query->get();

        if ($explicit !== []) {
            $missing = array_diff($explicit, $plans->pluck('id')->map('intval')->all());
            if ($missing !== []) {
                $this->error('套餐不存在：' . implode(', ', $missing));
                return null;
            }
            if (!$revert) {
                $notSelling = $plans->reject(fn (Plan $p) => in_array($groupId, $customizer->optionalAddonIds($p), true));
                if ($notSelling->isNotEmpty()) {
                    $this->error('这些套餐没有把该组配成「可选购」，补授权后用户将无法续费：'
                        . $notSelling->pluck('id')->implode(', '));
                    return null;
                }
            }
            return $plans;
        }

        return $revert
            ? $plans
            : $plans->filter(fn (Plan $p) => in_array($groupId, $customizer->optionalAddonIds($p), true))->values();
    }

    private function processPlan(
        PlanCustomizationService $customizer,
        Plan $plan,
        int $groupId,
        bool $revert,
        bool $dryRun,
        array &$mismatchedIds,
        array &$invalidIds,
    ): array {
        $stats = ['changed' => 0, 'already' => 0, 'mismatched' => 0, 'invalid' => 0];
        $base = $customizer->baseOptions($plan);

        $query = User::where('plan_id', $plan->id)->orderBy('id');
        if (!$this->option('include-expired')) {
            $query->where(fn ($q) => $q->where('expired_at', '>', time())->orWhereNull('expired_at'));
        }

        $apply = function () use ($query, $customizer, $plan, $groupId, $revert, $dryRun, $base, &$stats, &$mismatchedIds, &$invalidIds) {
            $query->chunkById(500, function ($users) use ($customizer, $plan, $groupId, $revert, $dryRun, $base, &$stats, &$mismatchedIds, &$invalidIds) {
                foreach ($users as $user) {
                    $current = is_array($user->plan_options) ? $user->plan_options : [];
                    $owned = array_values(array_unique(array_map('intval',
                        array_filter($current[PlanCustomizationService::ADDON_KEY] ?? [], 'is_numeric'))));
                    $has = in_array($groupId, $owned, true);
                    if ($revert ? !$has : $has) {
                        $stats['already']++;
                        continue;
                    }

                    // 资源三项：已有选择优先（自选套餐买过更高规格的人不能被压回基础值），缺的补套餐基础值。
                    $resources = array_intersect_key($current, PlanCustomizationService::LIMITS) + $base;

                    // 规格与用户实际值不符时跳过：套餐一旦配了增值组，每笔订单都会带快照，
                    // OrderService::hasChangedOptions 会拿快照规格和用户当前值逐项比对，
                    // 对不上就把续费判成「套餐变更」—— 旧周期作废、按折抵重开。
                    // （注意：这个风险来自「给套餐配增值组」这一步本身，不是本命令引入的；
                    //   这里只是借机把这批用户挑出来让你先处理掉。）
                    if (!$this->resourcesMatch($resources, $user)) {
                        $stats['mismatched']++;
                        $mismatchedIds[] = $user->id;
                        if (!$this->option('allow-mismatched')) {
                            continue;
                        }
                    }

                    $addons = $revert
                        ? array_values(array_diff($owned, [$groupId]))
                        : array_values(array_unique([...$owned, $groupId]));
                    sort($addons);
                    $selection = $resources + [PlanCustomizationService::ADDON_KEY => $addons];

                    // 写进去的选择必须是「续费时能通过校验」的，否则等于把用户锁死在续不了费的状态。
                    try {
                        $customizer->validateSelection($plan, $selection);
                    } catch (ApiException $e) {
                        $stats['invalid']++;
                        $invalidIds[] = $user->id;
                        continue;
                    }

                    $stats['changed']++;
                    if ($dryRun) {
                        continue;
                    }
                    $user->plan_options = $selection + [
                        // 派生兼容字段：热路径已不读它，这里与开通时写入的形状保持一致。
                        PlanCustomizationService::GRANTED_KEY => $customizer->grantedAddonGroups($plan, $selection),
                    ];
                    $user->save();
                }
            });
        };

        $dryRun ? $apply() : User::withoutEvents($apply);

        return $stats;
    }

    /** 用户实际的流量 / 设备数 / 速度是否与将要写入的选择一致（流量剔除本周期加购量）。 */
    private function resourcesMatch(array $selection, User $user): bool
    {
        $expected = $selection['transfer_enable'];
        if ($expected !== null) {
            $actual = (int) ($user->transfer_enable ?? 0) - (int) ($user->transfer_topup ?? 0);
            if ((int) $expected * self::BYTES_PER_GB !== $actual) {
                return false;
            }
        }
        return (int) $selection['device_limit'] === (int) $user->device_limit
            && (int) $selection['speed_limit'] === (int) $user->speed_limit;
    }

    private function reportMismatched(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->warn(sprintf('规格与套餐不一致的用户共 %d 个（前 20 个 ID）：%s',
            count($ids), implode(', ', array_slice($ids, 0, 20))));
        $this->line('  多半是客服手工调过流量 / 设备数 / 限速。套餐配了增值组之后，他们的续费会被判成');
        $this->line('  「套餐变更」——旧周期作废、按折抵重开。建议先把规格调回套餐标准值，或把他们');
        $this->line('  移到单独的套餐；确认可接受再加 --allow-mismatched 重跑。');
    }
}
