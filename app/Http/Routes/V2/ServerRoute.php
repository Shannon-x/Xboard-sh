<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\AccessLogController;
use App\Http\Controllers\V2\Server\ServerController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {

        $router->group([
            'prefix' => 'server',
            'middleware' => 'server'
        ], function ($route) {
            $route->post('handshake', [ServerController::class, 'handshake']);
            $route->post('report', [ServerController::class, 'report']);
            // Rust 节点的逐条访问记录批次（octet-stream，凭据在 query 里）；Go 版 V2bX 不调用
            $route->post('access-log', [AccessLogController::class, 'upload']);
            $route->get('config', [UniProxyController::class, 'config']);
            $route->get('user', [UniProxyController::class, 'user']);
            $route->post('push', [UniProxyController::class, 'push']);
            $route->post('alive', [UniProxyController::class, 'alive']);
            $route->get('alivelist', [UniProxyController::class, 'alivelist']);
            $route->post('status', [UniProxyController::class, 'status']);
        });
    }
}
