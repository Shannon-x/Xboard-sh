<?php

namespace App\Services;

use App\Models\Server;
use App\Support\TrafficBatchKey;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * WS 上的扩展协议（sufe.1）：上报带序号、面板逐条回 report.ack，加上定时对账 sync.check。
 *
 * 只有连接时在 query 里带 proto=sufe.1 的节点（Rust 节点）才会用到这些事件；不带的节点（Xboard-Node）
 * 看到的握手、首帧和推送与原来一样，只是 sync.config / sync.users 多了 etag、sync.config 多了 base_config。
 *
 * 每条上报的处理和对应的 HTTP 接口一致：
 *   report.traffic  ↔ POST UniProxy/push（带账本号 + 批次号，按批次身份只入账一次）
 *   report.activity ↔ POST UniProxy/alive（回同样的设备数），外加 V2 report 里的 online
 *   report.status   ↔ POST UniProxy/status（同样的校验），外加 V2 report 里的 metrics
 *   sync.check      ↔ GET UniProxy/config + user 带 If-None-Match（只回 ETag 变了的那份）
 *   request.alive   ↔ GET UniProxy/alivelist（有设备数限制的用户的跨节点设备数）
 *
 * report.ack 的 status：
 *   ok       处理完了（流量 = 已经进队列，和 HTTP 200 的保证一样）
 *   retry    面板这边临时出错（数据库、Redis），这条没有生效，节点稍后原样重发
 *   rejected 这条内容不合法，重发也没用；流量批次由节点改走 HTTP（拿到明确的状态码）
 *
 * 这里的代码跑在单进程的 Workerman 里，每条都要在毫秒级处理完。
 */
final class NodeWsProtocol
{
    public const PROTOCOL = 'sufe.1';

    public const FEATURES = [
        'report.traffic',
        'report.activity',
        'report.status',
        'sync.check',
        'sync.etag',
        'request.alive',
        'push.expiry',
    ];

    public const EVENTS = ['report.traffic', 'report.activity', 'report.status', 'sync.check', 'request.alive'];

    public const OK = 'ok';
    public const RETRY = 'retry';
    public const REJECTED = 'rejected';

    private const MAX_SEQ = 9007199254740991;

    public static function negotiated(array $params): bool
    {
        return ($params['proto'] ?? null) === self::PROTOCOL;
    }

    /**
     * WS 握手时认节点，规则和 HTTP 接口（Middleware\Server）一致：按 node_type 过滤，先匹配 code、再匹配 id。
     * 以前直接 Server::find(id)：现网有几十个节点的 code 是数字、和别的节点 id 撞号（如 id19 的 code 是 20），
     * 用 node_id=20 连上来的节点在 HTTP 上记到 id19、在 WS 上记到 id20，同一批流量重发时两边各记一次。
     */
    public static function resolveNode(array $params): ?Server
    {
        $raw = $params['node_id'] ?? '';
        if (!is_scalar($raw) || (string) $raw === '') {
            return null;
        }
        $type = $params['node_type'] ?? null;
        $type = is_string($type) && $type !== '' && $type !== 'v2node' ? $type : null;
        if (!Server::isValidType($type)) {
            return null;
        }
        return ServerService::getServer((string) $raw, Server::normalizeType($type));
    }

    /**
     * auth.success 里回给节点的 node_id：回它自己连上来时用的号，不回按 code 对应到的面板内部 id。
     * 节点（Rust 节点、Xboard-Node）拿这个号核对是不是连对了，号不一样会当作认证失败，
     * code 和别的节点 id 撞号的节点就永远连不上 WS。按 code 认到的节点只在面板内部登记。
     */
    public static function ackNodeId(array $params, Server $node): int
    {
        $raw = $params['node_id'] ?? null;
        if (is_string($raw) && preg_match('/^(0|[1-9][0-9]{0,9})$/', $raw) && (int) $raw <= 4294967295) {
            return (int) $raw;
        }
        return (int) $node->id;
    }

    public static function authSuccess(int $nodeId, bool $extended): array
    {
        $data = ['node_id' => $nodeId];
        if ($extended) {
            $data['protocol'] = self::PROTOCOL;
            $data['features'] = self::FEATURES;
        }
        return ['event' => 'auth.success', 'data' => $data];
    }

    /** 刚连上时推的全量：配置在前，名单在后 */
    public static function fullSync(Server $node): array
    {
        return [
            ['event' => 'sync.config', 'data' => NodePayload::configFrame($node)],
            ['event' => 'sync.users', 'data' => NodePayload::usersFrame($node)],
        ];
    }

    /**
     * @return array<int, array>|null 要回给节点的帧；不是扩展事件时返回 null（交给原来的分支处理）
     */
    public static function handle(int $nodeId, string $event, mixed $data): ?array
    {
        if (!in_array($event, self::EVENTS, true)) {
            return null;
        }
        $seq = is_array($data) ? self::seq($data['seq'] ?? null) : null;
        if ($seq === null) {
            return [['event' => 'error', 'data' => ['message' => 'invalid seq', 'for' => $event]]];
        }
        try {
            $node = Server::find($nodeId);
            if (!$node) {
                return [self::ack($seq, self::REJECTED, ['error' => 'node not found'])];
            }
            return match ($event) {
                'report.traffic' => [self::traffic($node, $seq, $data)],
                'report.activity' => [self::activity($node, $seq, $data)],
                'report.status' => [self::status($node, $seq, $data)],
                'sync.check' => self::check($node, $seq, $data),
                'request.alive' => [self::alive($node, $seq)],
            };
        } catch (\Throwable $e) {
            // 数据库断线、Redis 出错：这条没有生效，让节点重发
            Log::warning("[WS] Node#{$nodeId} {$event} failed: {$e->getMessage()}");
            return [self::ack($seq, self::RETRY)];
        }
    }

    public static function ack(int $seq, string $status, array $extra = []): array
    {
        return ['event' => 'report.ack', 'data' => ['seq' => $seq, 'status' => $status] + $extra];
    }

    private static function seq(mixed $seq): ?int
    {
        return is_int($seq) && $seq >= 0 && $seq <= self::MAX_SEQ ? $seq : null;
    }

    private static function traffic(Server $node, int $seq, array $data): array
    {
        $parentKey = TrafficBatchKey::from((string) $node->type, (int) $node->id, $data['ledger'] ?? null, $data['batch'] ?? null);
        $traffic = $data['traffic'] ?? null;
        if ($parentKey === null || !is_array($traffic)) {
            return self::ack($seq, self::REJECTED, ['error' => 'invalid batch']);
        }
        // 与 UniProxy/push 相同的清洗和缓存
        $rows = ServerService::sanitizeTrafficData($traffic);
        if (empty($rows)) {
            return self::ack($seq, self::OK);
        }
        $type = strtoupper((string) $node->type);
        Cache::put(CacheKey::get("SERVER_{$type}_ONLINE_USER", $node->id), count($rows), 3600);
        Cache::put(CacheKey::get("SERVER_{$type}_LAST_PUSH_AT", $node->id), time(), 3600);
        (new UserService())->trafficFetch($node, (string) $node->type, $rows, $parentKey);
        return self::ack($seq, self::OK);
    }

    private static function activity(Server $node, int $seq, array $data): array
    {
        $extra = [];
        if (array_key_exists('alive', $data)) {
            if (!is_array($data['alive'])) {
                return self::ack($seq, self::REJECTED, ['error' => 'invalid alive']);
            }
            // 与 UniProxy/alive 相同：全量快照，回受影响用户的跨节点设备数
            $devices = app(DeviceStateService::class);
            $affected = $devices->syncNodeDevices((int) $node->id, $data['alive']);
            $extra['alive'] = (object) $devices->getDeviceCounts($affected);
        }
        if (isset($data['online']) && is_array($data['online'])) {
            // 与 V2 report 的 online 相同
            $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
            foreach ($data['online'] as $uid => $conn) {
                if (!is_numeric($uid) || (int) $uid <= 0 || !is_numeric($conn)) {
                    continue;
                }
                Cache::put(CacheKey::get("USER_ONLINE_CONN_{$node->type}_{$node->id}", (int) $uid), (int) $conn, $cacheTime);
            }
        }
        return self::ack($seq, self::OK, $extra);
    }

    private static function status(Server $node, int $seq, array $data): array
    {
        $type = strtoupper((string) $node->type);
        // WS 在线时节点不再 HTTP 拉配置，面板「节点在线」看的 LAST_CHECK_AT 由负载上报续上
        Cache::put(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $node->id), time(), 3600);

        if (isset($data['status'])) {
            $validator = Validator::make(is_array($data['status']) ? $data['status'] : [], [
                'cpu' => 'required|numeric|min:0|max:100',
                'mem.total' => 'required|integer|min:0',
                'mem.used' => 'required|integer|min:0',
                'swap.total' => 'required|integer|min:0',
                'swap.used' => 'required|integer|min:0',
                'disk.total' => 'required|integer|min:0',
                'disk.used' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) {
                return self::ack($seq, self::REJECTED, ['error' => 'invalid status']);
            }
            $status = $validator->validated();
            // 与 UniProxy/status 写入的内容相同
            $statusData = [
                'cpu' => (float) $status['cpu'],
                'mem' => ['total' => (int) $status['mem']['total'], 'used' => (int) $status['mem']['used']],
                'swap' => ['total' => (int) $status['swap']['total'], 'used' => (int) $status['swap']['used']],
                'disk' => ['total' => (int) $status['disk']['total'], 'used' => (int) $status['disk']['used']],
                'updated_at' => now()->timestamp,
            ];
            $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
            cache([
                CacheKey::get("SERVER_{$type}_LOAD_STATUS", $node->id) => $statusData,
                CacheKey::get("SERVER_{$type}_LAST_LOAD_AT", $node->id) => now()->timestamp,
            ], $cacheTime);
        }
        if (isset($data['metrics']) && is_array($data['metrics']) && $data['metrics'] !== []) {
            ServerService::updateMetrics($node, $data['metrics']);
        }
        return self::ack($seq, self::OK);
    }

    /** 与 UniProxy/alivelist 相同 */
    private static function alive(Server $node, int $seq): array
    {
        $deviceLimitUsers = ServerService::getAvailableUsers($node)->where('device_limit', '>', 0);
        $alive = app(DeviceStateService::class)->getAliveList(collect($deviceLimitUsers));
        return self::ack($seq, self::OK, ['alive' => (object) $alive]);
    }

    /**
     * 定时对账：节点报上它手里配置和名单的 ETag，面板只回变了的那份，最后回 sync.ok。
     * 补 Redis pub/sub 丢掉的推送，以及没有推送的变化。
     */
    private static function check(Server $node, int $seq, array $data): array
    {
        $type = strtoupper((string) $node->type);
        Cache::put(CacheKey::get("SERVER_{$type}_LAST_CHECK_AT", $node->id), time(), 3600);

        $frames = [];
        $result = ['seq' => $seq, 'config' => 'same', 'users' => 'same'];

        $config = NodePayload::config($node);
        $configEtag = NodePayload::configEtag($config);
        if (NodePayload::normalizeEtag($data['config_etag'] ?? null) !== $configEtag) {
            $frames[] = ['event' => 'sync.config', 'data' => ['config' => $config, 'etag' => $configEtag]];
            $result['config'] = 'sent';
        }
        $users = NodePayload::users($node);
        $usersEtag = NodePayload::usersEtag($users);
        if (NodePayload::normalizeEtag($data['users_etag'] ?? null) !== $usersEtag) {
            $frames[] = ['event' => 'sync.users', 'data' => ['users' => $users->toArray(), 'etag' => $usersEtag]];
            $result['users'] = 'sent';
        }
        $frames[] = ['event' => 'sync.ok', 'data' => $result];
        return $frames;
    }
}
