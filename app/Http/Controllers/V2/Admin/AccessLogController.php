<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AccessLogQueryJob;
use App\Models\AccessLogFile;
use App\Models\AccessLogQuery;
use App\Models\User;
use App\Services\AccessLog\AccessLogConfig;
use App\Services\AccessLog\AccessLogQueryService;
use App\Services\AccessLog\AccessLogStorage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台：用户访问记录。
 *
 * 全部用 POST，且路径不以 fetch / detail 结尾：这样只读接口令牌调不到（访问记录比普通查询敏感），
 * 每次查看都会进后台审计日志（RequestLog）。明细查询另在 v2_access_log_query 记一行。
 */
class AccessLogController extends Controller
{
    /** 功能状态和最近 24 小时各节点的上传情况 */
    public function overview()
    {
        $config = AccessLogConfig::fromSettings();
        $since = now()->getTimestamp() - 86400;
        $nodes = AccessLogFile::query()
            ->where('created_at', '>=', $since)
            ->groupBy('node_type', 'node_id')
            ->get([
                'node_type',
                'node_id',
                DB::raw('COUNT(*) as files'),
                DB::raw('SUM(records) as records'),
                DB::raw('SUM(dropped) as dropped'),
                DB::raw('SUM(bytes) as bytes'),
                DB::raw('MAX(created_at) as last_upload_at'),
            ]);
        $names = AccessLogQueryService::nodeNames($nodes->map(fn($n) => [$n->node_type, $n->node_id])->all());
        return $this->success([
            'enable' => $config->enable,
            'storage_configured' => $config->storageConfigured(),
            'recording' => $config->recording(),
            'retention_days' => $config->retentionDays,
            'max_query_days' => AccessLogQueryService::MAX_DAYS,
            'nodes' => $nodes->map(fn($n) => [
                'node_type' => $n->node_type,
                'node_id' => (int) $n->node_id,
                'node_name' => $names["{$n->node_type}-{$n->node_id}"] ?? null,
                'files' => (int) $n->files,
                'records' => (int) $n->records,
                'dropped' => (int) $n->dropped,
                'bytes' => (int) $n->bytes,
                'last_upload_at' => (int) $n->last_upload_at,
            ])->values(),
        ]);
    }

    /** 某用户每天、每个节点的连接数和流量 */
    public function summary(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);
        $config = AccessLogConfig::fromSettings();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(6);
        if ($from->gt($to) || $from->diffInDays($to) >= $config->retentionDays) {
            return $this->fail([422, "日期范围需在 {$config->retentionDays} 天之内"]);
        }
        $user = User::find($data['user_id'], ['id', 'email']);
        if (!$user) {
            return $this->fail([404, '用户不存在']);
        }
        return $this->success([
            'user' => ['id' => $user->id, 'email' => $user->email],
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            ...AccessLogQueryService::summary($user->id, $from->format('Y-m-d'), $to->format('Y-m-d')),
        ]);
    }

    /** 发起一次明细查询 / 导出（排队执行），返回查询号 */
    public function query(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|min:1',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d',
            'host' => 'nullable|string|max:253',
            'network' => 'nullable|in:tcp,udp',
        ]);
        $config = AccessLogConfig::fromSettings();
        if (!$config->storageConfigured()) {
            return $this->fail([400, '访问记录存储未配置']);
        }
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->gt($to) || $from->diffInDays($to) >= AccessLogQueryService::MAX_DAYS) {
            return $this->fail([422, '明细一次最多查 ' . AccessLogQueryService::MAX_DAYS . ' 天']);
        }
        if (!User::whereKey($data['user_id'])->exists()) {
            return $this->fail([404, '用户不存在']);
        }
        $query = AccessLogQuery::create([
            'admin_id' => $request->user()->id,
            'user_id' => (int) $data['user_id'],
            'date_from' => $from->format('Y-m-d'),
            'date_to' => $to->format('Y-m-d'),
            'filter' => array_filter([
                'host' => isset($data['host']) ? strtolower(trim($data['host'])) : null,
                'network' => $data['network'] ?? null,
            ]),
            'status' => AccessLogQuery::STATUS_PENDING,
        ]);
        AccessLogQueryJob::dispatch($query->id);
        return $this->success(['id' => $query->id]);
    }

    /** 明细查询记录（也是查询审计）：谁在什么时候查了哪个用户的哪几天 */
    public function queries(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ]);
        $page = AccessLogQuery::query()
            ->with('admin:id,email')
            ->when($data['user_id'] ?? null, fn($q, $uid) => $q->where('user_id', $uid))
            ->orderByDesc('id')
            ->paginate($data['pageSize'] ?? 20, ['*'], 'current', $data['current'] ?? 1);
        return $this->paginate($page);
    }

    /** 一次明细查询的结果：按目标汇总和导出链接（10 分钟有效） */
    public function result(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1']);
        $query = AccessLogQuery::with('admin:id,email')->find($data['id']);
        if (!$query) {
            return $this->fail([404, '查询不存在']);
        }
        $download = null;
        if ($query->status === AccessLogQuery::STATUS_DONE && $query->export_key) {
            $download = app(AccessLogStorage::class)->temporaryUrl(
                $query->export_key,
                600,
                "access-log-user{$query->user_id}-{$query->date_from}-{$query->date_to}.csv"
            );
        }
        return $this->success([...$query->toArray(), 'download_url' => $download]);
    }

    /** 「测试存储连接」：用表单里的值（未保存也行）写入、按范围读回、删除一个探针对象 */
    public function testStorage(Request $request)
    {
        $override = array_filter(
            $request->only(array_keys(AccessLogConfig::DEFAULTS)),
            static fn($v) => $v !== null && $v !== ''
        );
        $config = AccessLogConfig::fromSettings($override);
        try {
            (new AccessLogStorage($config->s3))->probe();
        } catch (\Throwable $e) {
            return $this->fail([400, '存储测试失败：' . $e->getMessage()]);
        }
        return $this->success([
            'endpoint' => $config->s3['endpoint'] ?: "https://s3.{$config->s3['region']}.amazonaws.com",
            'bucket' => $config->s3['bucket'],
        ]);
    }
}
