<?php

use App\Http\Middleware\McpAuth;
use App\Mcp\Servers\AccountServer;
use Laravel\Mcp\Facades\Mcp;

// 客户 AI 助手（MCP）。mcp_enable 为 0 时 McpAuth 一律 403，整条路由形同不存在。
Mcp::web('/mcp', AccountServer::class)
    ->middleware([\App\Http\Middleware\ApplyRuntimeSettings::class, McpAuth::class]);
