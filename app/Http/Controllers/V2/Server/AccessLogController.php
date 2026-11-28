<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\AccessLog\AccessLogBatch;
use App\Services\AccessLog\AccessLogConfig;
use App\Services\AccessLog\AccessLogIngest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * POST /api/v2/server/access-log：Rust 节点上传一个访问记录批次。
 *
 * 返回码约定（节点按它决定是否重传）：200 已收下（或功能已关、直接丢弃）；400 / 413 批次本身有问题，
 * 节点删掉不再重传；503 存储暂不可用，节点退避后原样重传。
 */
class AccessLogController extends Controller
{
    public function upload(Request $request, AccessLogIngest $ingest): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        $config = AccessLogConfig::fromSettings();
        if (!$config->recording()) {
            return response()->json(['data' => true, 'stored' => false]);
        }
        $body = $request->getContent();
        if (strlen($body) > AccessLogBatch::MAX_BODY) {
            return response()->json(['message' => 'batch too large'], 413);
        }
        try {
            $batch = AccessLogBatch::parse($body);
        } catch (InvalidArgumentException $e) {
            Log::info('access log batch rejected', ['node' => "{$node->type}-{$node->id}", 'reason' => $e->getMessage()]);
            return response()->json(['message' => $e->getMessage()], 400);
        }
        try {
            $first = $ingest->store((string) $node->type, (int) $node->id, $batch);
        } catch (\Throwable $e) {
            Log::warning('access log batch not stored', ['node' => "{$node->type}-{$node->id}", 'error' => $e->getMessage()]);
            return response()->json(['message' => 'storage unavailable'], 503);
        }
        return response()->json(['data' => true, 'stored' => true, 'duplicate' => !$first]);
    }
}
