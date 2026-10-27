<?php

namespace App\Http\Controllers\Mcp;

use App\Services\Mcp\McpOAuthClients;
use App\Services\Mcp\McpOAuthError;
use App\Services\Mcp\McpOAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * 一键授权（OAuth）给 AI 软件用的几个公开地址，挂在接入地址同一个域名下（见 routes/ai.php）：
 * 两份 well-known 元数据、动态注册、授权入口（转到网站授权页）、换令牌。
 * 客户在授权页点「允许 / 拒绝」走的是面板接口（V1\User\McpAuthorizeController）。
 *
 * 功能没开（mcp_enable、mcp_oauth_enable 任一为 0，或接入地址没填）时一律 404，等于这些地址不存在。
 */
class OAuthController extends Controller
{
    public function protectedResource(): JsonResponse
    {
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return self::notFound();
        }
        return self::json($server->protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return self::notFound();
        }
        return self::json($server->authorizationServerMetadata());
    }

    public function register(Request $request): JsonResponse
    {
        if (!McpOAuthServer::make()->config()->oauthActive()) {
            return self::notFound();
        }
        try {
            return self::json(McpOAuthClients::make()->register($request->json()->all() ?: $request->all(), $request->ip()), 201);
        } catch (McpOAuthError $e) {
            return self::json($e->toArray(), $e->status);
        }
    }

    public function authorize(Request $request)
    {
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return self::notFound();
        }
        return redirect()->away($server->authorize($request->query()));
    }

    public function token(Request $request): JsonResponse
    {
        $server = McpOAuthServer::make();
        if (!$server->config()->oauthActive()) {
            return self::notFound();
        }
        $basic = [$request->getUser(), $request->getPassword()];
        // client_secret_basic 里的 id / secret 按 RFC 6749 先做过 form 编码
        $basic = array_map(fn ($v) => $v === null ? null : urldecode($v), $basic);
        try {
            return self::json($server->token($request->all(), $basic));
        } catch (McpOAuthError $e) {
            $response = self::json($e->toArray(), $e->status);
            return $e->status === 401 ? $response->header('WWW-Authenticate', 'Basic realm="mcp"') : $response;
        }
    }

    private static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found'], 404);
    }
}
