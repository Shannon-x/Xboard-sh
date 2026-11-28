<?php

namespace App\Http\Controllers\V2\Server;

use App\Console\Commands\NodeWebSocketServer;
use App\Http\Controllers\Controller;
use App\Services\AccessLog\AccessLogConfig;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Log;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        // WS 进程没在跑（心跳过期）时不让节点连：节点直接走 HTTP，不用先连一次失败再回落
        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWebSocketServer::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                // 与上游一致：默认走面板同源的 /ws（反代到 127.0.0.1:8076），不用另开端口和证书
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket,
            // 只有 Rust 节点调握手；旧版 Rust 节点忽略不认识的字段
            'access_log' => AccessLogConfig::fromSettings()->forNode(),
        ]);
    }

    /**
     * node report api - merge traffic + alive + status
     * POST /api/v2/server/node/report
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        $nodeType = $node->type;
        $nodeId = $node->id;

        Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_CHECK_AT', $nodeId), time(), 3600);

        // hanle traffic data
        $traffic = $request->input('traffic');
        if (is_array($traffic) && !empty($traffic)) {
            // 统一清洗：拒负数、非整数、超大值、非正整数 uid（见 ServerService::sanitizeTrafficData）
            $data = ServerService::sanitizeTrafficData($traffic);

            if (!empty($data)) {
                Cache::put(
                    CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId),
                    count($data),
                    3600
                );
                Cache::put(
                    CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId),
                    time(),
                    3600
                );
                $userService = new UserService();
                $userService->trafficFetch($node, $nodeType, $data);
            }
        }

        // handle alive data
        $alive = $request->input('alive');
        $aliveDelta = null;
        if (array_key_exists('alive', $request->all()) && is_array($alive)) {
            $deviceStateService = app(DeviceStateService::class);
            $affectedUserIds = $deviceStateService->syncNodeDevices($nodeId, $alive);
            $aliveDelta = $deviceStateService->getDeviceCounts($affectedUserIds);
        }

        // handle active connections
        $online = $request->input('online');
        if (is_array($online) && !empty($online)) {
            $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
            foreach ($online as $uid => $conn) {
                $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
                Cache::put($cacheKey, (int) $conn, $cacheTime);
            }
        }

        // handle node status
        $status = $request->input('status');
        if (is_array($status) && !empty($status)) {
            $statusData = [
                'cpu' => (float) ($status['cpu'] ?? 0),
                'mem' => [
                    'total' => (int) ($status['mem']['total'] ?? 0),
                    'used' => (int) ($status['mem']['used'] ?? 0),
                ],
                'swap' => [
                    'total' => (int) ($status['swap']['total'] ?? 0),
                    'used' => (int) ($status['swap']['used'] ?? 0),
                ],
                'disk' => [
                    'total' => (int) ($status['disk']['total'] ?? 0),
                    'used' => (int) ($status['disk']['used'] ?? 0),
                ],
                'updated_at' => now()->timestamp,
                'kernel_status' => $status['kernel_status'] ?? null,
            ];

            $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
            cache([
                CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LOAD_STATUS', $nodeId) => $statusData,
                CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_LOAD_AT', $nodeId) => now()->timestamp,
            ], $cacheTime);
        }

        // handle node metrics (Metrics)
        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        }

        $response = ['data' => true];
        if ($aliveDelta !== null) {
            $response['alive'] = (object) $aliveDelta;
        }
        return response()->json($response);
    }
}
