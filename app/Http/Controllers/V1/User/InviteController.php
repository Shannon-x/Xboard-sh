<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Support\InviteCodeRules;
use App\Utils\Helper;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    /**
     * 生成邀请码。
     *
     * 双路由复用：GET /invite/save（老前端，无参随机码，行为不变）
     *            POST /invite/save（新前端，可带 code 自定义）。
     */
    public function save(Request $request)
    {
        if (InviteCode::where('user_id', $request->user()->id)->where('status', InviteCode::STATUS_UNUSED)->count() >= admin_setting('invite_gen_limit', 5)) {
            return $this->fail([400, __('The maximum number of creations has been reached')]);
        }

        // is_string 守卫：code[]=x 会让 input() 返回数组，(string)$array 触发
        // 「Array to string conversion」warning 并把字面量 'Array' 当成码
        $rawCode = $request->input('code', '');
        $customCode = is_string($rawCode) ? trim($rawCode) : '';
        if ($customCode === '') {
            return $this->createRandomCode($request->user()->id);
        }
        return $this->createCustomCode($request->user()->id, $customCode);
    }

    private function createRandomCode(int $userId)
    {
        // code 上有唯一索引后，随机生成理论上可碰撞：先查重，唯一索引兜底并发
        do {
            $code = Helper::randomChar(8);
        } while (InviteCode::where('code', $code)->exists());

        $inviteCode = new InviteCode();
        $inviteCode->user_id = $userId;
        $inviteCode->code = $code;
        try {
            return $this->success($inviteCode->save());
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // 并发撞码（62^8 空间，概率趋零）：让用户重试即可
                return $this->fail([400, __('Please try again')]);
            }
            throw $e;
        }
    }

    private function createCustomCode(int $userId, string $customCode)
    {
        if (!(int) admin_setting('invite_custom_code_enable', 1)) {
            return $this->fail([400, __('Custom invite codes are not enabled')]);
        }

        $error = InviteCodeRules::validateFormat($customCode);
        if ($error !== null) {
            return $this->fail([422, __($error)]);
        }

        // 大小写不敏感查重（MySQL ci collation 下与唯一索引语义一致；SQLite 下补齐 ci 防护）。
        // 命中自己的墓碑（删除过的同名码）则恢复；命中他人或自己的活跃/已用码则拒绝。
        $existing = InviteCode::whereRaw('LOWER(code) = ?', [strtolower($customCode)])->first();
        if ($existing) {
            $isMine = (int) $existing->user_id === (int) $userId;
            $isDeleted = (int) $existing->getRawOriginal('status') === InviteCode::STATUS_DELETED;
            if ($isMine && $isDeleted) {
                $restored = InviteCode::where('id', $existing->id)
                    ->where('status', InviteCode::STATUS_DELETED)
                    ->update([
                        'status' => InviteCode::STATUS_UNUSED,
                        'updated_at' => time(),
                    ]);
                return $this->success((bool) $restored);
            }
            return $this->fail([400, __('This invite code is already taken')]);
        }

        $inviteCode = new InviteCode();
        $inviteCode->user_id = $userId;
        $inviteCode->code = $customCode;
        try {
            return $this->success($inviteCode->save());
        } catch (\Illuminate\Database\QueryException $e) {
            // 并发抢注同名码：唯一索引是最终裁判
            if ($this->isUniqueViolation($e)) {
                return $this->fail([400, __('This invite code is already taken')]);
            }
            throw $e;
        }
    }

    /**
     * 唯一约束冲突判定，跨驱动：
     *   MySQL  → errorInfo[1] = 1062（ER_DUP_ENTRY）
     *   SQLite → errorInfo[1] = 19，二者 SQLSTATE 均为 '23000'（完整性约束冲突）
     * 只认 1062 会让 SQLite（测试库 / 小型自托管）下的撞码漏 catch 直接抛 500。
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23000'
            || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * 删除（墓碑化）自己的未使用邀请码。
     *
     * 非硬删除：code 唯一索引继续占住该字符串，外流链接不会被他人抢注劫持；
     * 本人重建同名码时自动恢复（createCustomCode 的墓碑恢复分支）。
     */
    public function delete(Request $request)
    {
        $rawCode = $request->input('code', '');
        $code = is_string($rawCode) ? trim($rawCode) : '';
        if ($code === '') {
            return $this->fail([422, __('Invalid invitation code')]);
        }

        $affected = InviteCode::where('user_id', $request->user()->id)
            ->where('code', $code)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->update([
                'status' => InviteCode::STATUS_DELETED,
                'updated_at' => time(),
            ]);

        if (!$affected) {
            return $this->fail([400, __('Invalid invitation code')]);
        }

        $this->pruneTombstones($request->user()->id);
        return $this->success(true);
    }

    /**
     * 墓碑 FIFO 回收：防止「创建→删除」循环无限囤积墓碑、批量占坑品牌词
     * （gen_limit 只约束活跃码，不约束墓碑数）。超出上限时硬删除最旧的墓碑，
     * 释放其字符串——被回收的是用户很久之前删除的码，抢注风险可接受。
     */
    private function pruneTombstones(int $userId): void
    {
        $keep = max(10, (int) admin_setting('invite_gen_limit', 5) * 2);
        $tombstones = InviteCode::where('user_id', $userId)
            ->where('status', InviteCode::STATUS_DELETED)
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->skip($keep)
            ->take(50)
            ->pluck('id');
        if ($tombstones->isNotEmpty()) {
            InviteCode::whereIn('id', $tombstones)
                ->where('status', InviteCode::STATUS_DELETED)
                ->delete();
        }
    }

    /**
     * 被邀请人列表（脱敏）：注册时间 + 产生佣金的订单数 + 贡献的已发放佣金合计。
     * 邮箱仅保留前 2 位 + 域名，防止邀请人借此联系/撞库被邀请人。
     */
    public function users(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = (int) $request->input('page_size', 10);
        $pageSize = $pageSize >= 10 ? min($pageSize, 100) : 10;

        $builder = User::where('invite_user_id', $request->user()->id)
            ->orderBy('id', 'DESC');
        $total = $builder->count();
        $invitees = $builder->forPage($current, $pageSize)->get(['id', 'email', 'created_at']);

        $aggregates = CommissionLog::where('invite_user_id', $request->user()->id)
            ->whereIn('user_id', $invitees->pluck('id'))
            ->selectRaw('user_id, COUNT(*) as order_count, SUM(get_amount) as commission_total')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $data = $invitees->map(function ($invitee) use ($aggregates) {
            $agg = $aggregates->get($invitee->id);
            return [
                'id' => $invitee->id,
                'email' => Helper::maskEmail($invitee->email),
                'created_at' => $invitee->created_at,
                'order_count' => (int) ($agg->order_count ?? 0),
                'commission_total' => (int) ($agg->commission_total ?? 0),
            ];
        });

        return response([
            'data' => $data,
            'total' => $total,
        ]);
    }

    public function details(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $pageSize = min((int) $pageSize, 100); // 与 users() 一致，封顶防一次拉全量序列化
        $builder = CommissionLog::where('invite_user_id', $request->user()->id)
            ->where('get_amount', '>', 0)
            ->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)
            ->get();

        // 批量补充买家脱敏邮箱（additive 字段，老前端忽略；不引入 N+1）
        $buyerEmails = User::whereIn('id', $details->pluck('user_id')->filter()->unique())
            ->pluck('email', 'id');
        $details->each(function ($log) use ($buyerEmails) {
            $email = $buyerEmails[$log->user_id] ?? null;
            $log->setAttribute('email_masked', $email !== null ? Helper::maskEmail($email) : null);
        });

        return response([
            'data' => ComissionLogResource::collection($details),
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $commission_rate = admin_setting('invite_commission', 10);
        $user = User::find($request->user()->id)
                ->load(['codes' => fn($query) => $query->where('status', 0)]);
        // 保持原 truthy 语义，与 OrderService::setInvite 一致：commission_rate 为 0/null 都回落全局默认。
        // （详见 OrderService::setInvite 注释——避免破坏 XBoard-admin「清空=跟随默认」契约。）
        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $user->id)
            ->sum('commission_balance');
        if (admin_setting('commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (admin_setting('commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $user->id)->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $user->id)
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        $data = [
            'codes' => InviteCodeResource::collection($user->codes),
            'stat' => $stat
        ];
        return $this->success($data);
    }
}
