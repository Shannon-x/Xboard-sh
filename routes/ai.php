<?php

use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\ApplyRuntimeSettings;
use App\Http\Middleware\McpAuth;
use App\Mcp\Servers\AccountServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// 客户 AI 助手（MCP）。mcp_enable 为 0 时 McpAuth 一律 403，整条路由形同不存在。
// 401 的 WWW-Authenticate 由 McpAuth 自己写（指向一键授权的元数据），不用包里那个按请求域名拼地址的
Mcp::web('/mcp', AccountServer::class)
    ->middleware([ApplyRuntimeSettings::class, McpAuth::class])
    ->withoutMiddleware(AddWwwAuthenticateHeader::class);

// 一键授权（OAuth）：ChatGPT、Claude、Grok 网页版据此跳到网站授权页。功能没开时全部 404。
// 接入域名除了 /mcp，还要把 /.well-known/oauth-* 和 /oauth/* 转到后端。
Route::middleware([ApplyRuntimeSettings::class])->group(function () {
    Route::get('/.well-known/oauth-protected-resource', [OAuthController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-protected-resource/mcp', [OAuthController::class, 'protectedResource']);
    Route::get('/.well-known/oauth-authorization-server', [OAuthController::class, 'authorizationServer']);
    Route::get('/.well-known/oauth-authorization-server/mcp', [OAuthController::class, 'authorizationServer']);
    Route::post('/oauth/register', [OAuthController::class, 'register'])->middleware('throttle:mcp-oauth-register');
    Route::get('/oauth/authorize', [OAuthController::class, 'authorize'])->middleware('throttle:mcp-oauth');
    Route::post('/oauth/token', [OAuthController::class, 'token'])->middleware('throttle:mcp-oauth');
});
