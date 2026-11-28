<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeSyncService;
use App\Services\NodeRegistry;
use App\Services\NodeWsProtocol;
use App\Services\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWebSocketServer extends Command
{
    protected $signature = 'ws-server
        {action=start : start | stop | restart | reload | status}
        {--d : Start in daemon mode}
        {--host=0.0.0.0 : Listen address}
        {--port=8076 : Listen port}';

    protected $description = 'Start the WebSocket server for node-panel synchronization';

    /** Auth timeout in seconds — close unauthenticated connections */
    private const AUTH_TIMEOUT = 10;

    /** Ping interval in seconds */
    private const PING_INTERVAL = 55;

    /**
     * WS 服务心跳：握手接口只在它新鲜时才让节点连 WS。WS 进程没在跑时，节点直接走 HTTP，
     * 不用先连一次失败再回落。
     */
    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    public function handle(): void
    {
        global $argv;
        $action = $this->argument('action');

        // 重新构建 argv 供 Workerman 解析
        $argv[1] = $action;
        if ($this->option('d')) {
            $argv[2] = '-d';
        }

        $host = $this->option('host');
        $port = $this->option('port');

        $worker = new Worker("websocket://{$host}:{$port}");
        $worker->count = 1;
        $worker->name = 'xboard-ws-server';

        // 设置日志和 PID 文件路径
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/xboard-ws-server.log'; // 指向具体文件，避免某些环境 php://stdout 的 stat 失败
        Worker::$pidFile = $logPath . '/xboard-ws-server.pid';

        $worker->onWorkerStart = function (Worker $worker) {
            $this->info("[WS] Worker started, pid={$worker->id}");
            $this->subscribeRedis();

            Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
            Timer::add(self::HEARTBEAT_INTERVAL, function () {
                try {
                    Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
                } catch (\Throwable $e) {
                    Log::warning("[WS] heartbeat failed: {$e->getMessage()}");
                }
            });

            // Periodic ping to detect dead connections
            Timer::add(self::PING_INTERVAL, function () {
                foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                    $conn = NodeRegistry::get($nodeId);
                    if ($conn) {
                        $conn->send(json_encode(['event' => 'ping']));
                    }
                }
            });

            // 定时推送设备状态给节点（每10秒）
            Timer::add(10, function () {
                $pendingNodeIds = Redis::spop('device:push_pending_nodes', 100);
                if (empty($pendingNodeIds)) {
                    return;
                }

                $deviceStateService = app(DeviceStateService::class);

                foreach ($pendingNodeIds as $nodeId) {
                    $nodeId = (int) $nodeId;
                    if (NodeRegistry::get($nodeId) !== null) {
                        $this->pushDeviceStateToNode($nodeId, $deviceStateService);
                    }
                }
            });
        };

        $worker->onConnect = function (TcpConnection $conn) {
            // Set auth timeout — must authenticate within N seconds or get disconnected
            $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
                if (empty($conn->nodeId)) {
                    $conn->close(json_encode([
                        'event' => 'error',
                        'data' => ['message' => 'auth timeout'],
                    ]));
                }
            }, [], false);
        };

        $worker->onWebSocketConnect = function (TcpConnection $conn, $httpMessage) {
            // Parse query string from the WebSocket upgrade request
            // In Workerman 4.x/5.x with onWebSocketConnect, the second arg can be a string or Request object
            $queryString = '';
            if (is_string($httpMessage)) {
                $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
            } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
                $queryString = $httpMessage->queryString();
            }

            parse_str($queryString, $params);

            $token = $params['token'] ?? '';
            $nodeId = (int) ($params['node_id'] ?? 0);
            // 带 proto=sufe.1 的节点（Rust 节点）用扩展协议：上报逐条确认、定时对账
            $extended = NodeWsProtocol::negotiated($params);

            // Authenticate
            $serverToken = admin_setting('server_token', '');
            if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'invalid token'],
                ]));
                return;
            }

            $node = Server::find($nodeId);
            if (!$node) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'node not found'],
                ]));
                return;
            }

            // Auth passed — cancel timeout, register connection
            if (isset($conn->authTimer)) {
                Timer::del($conn->authTimer);
            }

            $conn->nodeId = $nodeId;
            $conn->extended = $extended;
            NodeRegistry::add($nodeId, $conn);
            Cache::put("node_ws_alive:{$nodeId}", true, 86400);

            // 清理该节点的旧设备数据（节点重连后需重新上报全量）。
            // 扩展协议的节点连上后马上报全量快照（syncNodeDevices 按快照替换），断开时回落到 HTTP 继续报，
            // 不清：清了会让这个节点上的设备数在两次上报之间归零，设备数限制短暂失效。
            if (!$extended) {
                app(DeviceStateService::class)->clearAllNodeDevices($nodeId);
            }

            Log::debug("[WS] Node#{$nodeId} connected", [
                'remote' => $conn->getRemoteIp(),
                'total' => NodeRegistry::count(),
                'protocol' => $extended ? NodeWsProtocol::PROTOCOL : 'xboard',
            ]);

            // Send auth success
            $conn->send(json_encode(NodeWsProtocol::authSuccess($nodeId, $extended)));

            // Push full sync (config + users) immediately to this specific connection
            $this->pushFullSync($conn, $node);
        };

        $worker->onMessage = function (TcpConnection $conn, $data) {
            $msg = json_decode($data, true);
            if (!is_array($msg)) {
                return;
            }

            $event = $msg['event'] ?? '';
            $nodeId = $conn->nodeId ?? null;
            if (!is_string($event)) {
                return;
            }

            if ($nodeId && !empty($conn->extended)) {
                $frames = NodeWsProtocol::handle((int) $nodeId, $event, $msg['data'] ?? null);
                if ($frames !== null) {
                    foreach ($frames as $frame) {
                        $conn->send(json_encode($frame));
                    }
                    return;
                }
            }

            switch ($event) {
                case 'pong':
                    // Heartbeat response — node is alive
                    if ($nodeId) {
                        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
                    }
                    break;
                case 'node.status':
                    if ($nodeId && isset($msg['data'])) {
                        $this->handleNodeStatus($nodeId, $msg['data']);
                    }
                    break;
                case 'report.devices':
                    if ($nodeId && isset($msg['data'])) {
                        $this->handleDeviceReport($nodeId, $msg['data']);
                    }
                    break;
                case 'request.devices':
                    if ($nodeId) {
                        $this->handleDeviceRequest($conn, $nodeId);
                    }
                    break;
                default:
                    break;
            }
        };

        $worker->onClose = function (TcpConnection $conn) {
            if (!empty($conn->nodeId)) {
                $nodeId = $conn->nodeId;
                // 同一节点重连时旧连接的关闭可能晚于新连接注册：只有登记的还是这条连接才注销
                if (!NodeRegistry::removeIfCurrent($nodeId, $conn)) {
                    return;
                }
                Cache::forget("node_ws_alive:{$nodeId}");

                $affectedUserIds = [];
                if (empty($conn->extended)) {
                    $affectedUserIds = app(DeviceStateService::class)->clearAllNodeDevices($nodeId);
                }

                Log::debug("[WS] Node#{$nodeId} disconnected", [
                    'total' => NodeRegistry::count(),
                    'affected_users' => count($affectedUserIds),
                ]);
            }
        };

        Worker::runAll();
    }

    /**
     * Handle status data pushed from node via WebSocket
     */
    private function handleNodeStatus(int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);

        // Update last check-in cache
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);

        // Update metrics cache via Service
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated via WebSocket");
    }

    /**
     * Handle device report from node via WebSocket
     * 
     * 节点发送全量设备列表，面板负责差异计算
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     * 
     * 示例: {"event": "report.devices", "data": {"123": ["1.1.1.1", "2.2.2.2"], "456": ["3.3.3.3"]}}
     */
    private function handleDeviceReport(int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);
        $service->syncNodeDevices($nodeId, $data);

        // 标记该节点待推送（由定时器批量处理）
        Redis::sadd('device:push_pending_nodes', $nodeId);

        Log::debug("[WS] Node#{$nodeId} synced " . count($data) . " users");
    }

    /**
     * 推送全量设备状态给指定节点
     */
    private function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        // 获取该节点关联的所有用户
        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        // 获取这些用户的设备列表
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    /**
     * Handle device state request from node via WebSocket
     */
    private function handleDeviceRequest(TcpConnection $conn, int $nodeId): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $deviceStateService = app(DeviceStateService::class);
        $devices = $deviceStateService->getUsersDevices($userIds);

        $conn->send(json_encode([
            'event' => 'sync.devices',
            'data' => ['users' => $devices],
        ]));

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Subscribe to Redis pub/sub channel for receiving push commands from Laravel.
     * Laravel app publishes to "node:push" channel, Workerman picks it up and forwards to the right node.
     */
    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        // Handle Unix Socket connection
        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        // Get Laravel Redis prefix to match publish()
        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';

        $redis->subscribe([$channel], function ($chan, $message) {
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $nodeId = $payload['node_id'] ?? null;
            $event = $payload['event'] ?? '';
            $data = $payload['data'] ?? [];

            if (!$nodeId || !$event) {
                return;
            }

            $sent = NodeRegistry::send((int) $nodeId, $event, $data);
            if ($sent) {
                Log::debug("[WS] Pushed {$event} to node#{$nodeId}, data: " . json_encode($data));
            }
        });

        $this->info("[WS] Subscribed to Redis channel: {$channel}");
    }

    /**
     * Push full config + users to a newly connected node.
     */
    private function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = $conn->nodeId;
        // 配置（与 HTTP config 响应相同，含 base_config）和名单，各带 ETag
        $frames = NodeWsProtocol::fullSync($node);
        foreach ($frames as $frame) {
            $conn->send(json_encode($frame));
        }

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($frames[1]['data']['users']),
        ]);
    }
}
