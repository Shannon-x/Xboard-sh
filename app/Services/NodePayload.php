<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * 下发给节点的配置和名单，HTTP 和 WS 用同一份内容、同一个 ETag。
 *
 * 和 UniProxyController::config() / user() 的响应逐字节相同（测试里核对）：节点在 WS 上收到的配置
 * 就是 HTTP 拉到的那份，ETag 也一样，WS 断开回落到 HTTP 时带着 WS 上拿到的 ETag 去拉，面板回 304。
 * HTTP 控制器本身不改，避免碰到 Go 版 V2bX 的接口。
 */
final class NodePayload
{
    /** 与 UniProxyController::config() 的响应体相同（节点配置 + base_config） */
    public static function config(Server $node): array
    {
        $response = ServerService::buildNodeConfig($node);
        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            'node_report_min_traffic' => (int) admin_setting('server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int) admin_setting('server_device_online_min_traffic', 0),
        ];
        return $response;
    }

    public static function users(Server $node): Collection
    {
        return ServerService::getAvailableUsers($node);
    }

    /** HTTP 响应头里的 ETag 是加了引号的同一个值 */
    public static function configEtag(array $config): string
    {
        return sha1(json_encode($config));
    }

    public static function usersEtag(Collection $users): string
    {
        return sha1(json_encode(['users' => $users]));
    }

    /** sync.config 的 data：config 是 HTTP 配置响应体，etag 是它的 ETag（不带引号） */
    public static function configFrame(Server $node): array
    {
        $config = self::config($node);
        return ['config' => $config, 'etag' => self::configEtag($config)];
    }

    /** sync.users 的 data：users 与 HTTP 名单相同 */
    public static function usersFrame(Server $node): array
    {
        $users = self::users($node);
        return ['users' => $users->toArray(), 'etag' => self::usersEtag($users)];
    }

    /** 节点发来的 ETag 可能带引号（从 HTTP 响应头原样存下）或 W/ 前缀 */
    public static function normalizeEtag(mixed $etag): ?string
    {
        if (!is_string($etag)) {
            return null;
        }
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }
        $etag = trim($etag, '"');
        return preg_match('/^[0-9a-f]{40}$/D', $etag) === 1 ? $etag : null;
    }
}
