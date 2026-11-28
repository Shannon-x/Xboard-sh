<?php

namespace App\Support;

use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

/**
 * 流量上报的「批次身份」：同一节点、同一账本、同一批次号的上报只入账一次。
 *
 * Rust 节点给每份流量账本生成一个 128 位随机账本号（32 个十六进制字符），账本里每一批有递增的批次号。
 * 一批没拿到确认（超时、断线）时，节点原样重发同一个账本号 + 批次号，HTTP 和 WS 都一样。
 * 面板把这三样（加节点类型）算成 TrafficReportJob 的父键，子任务的回执键由父键决定
 * （见 TrafficReportJob::receiptKey），所以重发的那份到了 JobReceipt 就被认出来、不再记账。
 *
 * 不带这两个头的请求（Go 版 V2bX、旧版 Rust 节点）得到 null，走原来的路径，行为不变。
 * 回执只保留 JobReceipt::RETENTION_DAYS 天，节点必须在这之前停止重发（节点侧 7 天后隔离）。
 */
final class TrafficBatchKey
{
    public const LEDGER_HEADER = 'X-Sufe-Traffic-Ledger';
    public const BATCH_HEADER = 'X-Sufe-Traffic-Batch';
    /** 响应头：告诉节点这次上报按批次身份去重了，没拿到确认的批次可以原样重发 */
    public const IDEMPOTENT_HEADER = 'X-Sufe-Traffic-Idempotent';

    /** 父键命名空间（UUID v5），固定不变：改了会让重发的批次认不出来 */
    private const NAMESPACE = '5c1f0e3a-9d7b-4c2e-8a61-3f4b2d9e7c10';

    /** JSON 里整数能精确表示的上限，节点的批次号是 u64 但实际远小于它 */
    private const MAX_BATCH = 9007199254740991;

    public static function fromRequest(Request $request, string $nodeType, int $nodeId): ?string
    {
        $ledger = $request->header(self::LEDGER_HEADER);
        $batch = $request->header(self::BATCH_HEADER);
        if (!is_string($ledger) || !is_string($batch)) {
            return null;
        }
        return self::from($nodeType, $nodeId, $ledger, $batch);
    }

    /**
     * @param mixed $ledger 32 个小写十六进制字符
     * @param mixed $batch  正整数（WS 里是 JSON 整数，HTTP 头里是十进制字符串）
     */
    public static function from(string $nodeType, int $nodeId, mixed $ledger, mixed $batch): ?string
    {
        if (!is_string($ledger) || preg_match('/^[0-9a-f]{32}$/D', $ledger) !== 1) {
            return null;
        }
        if (is_int($batch)) {
            $number = $batch;
        } elseif (is_string($batch) && preg_match('/^[1-9][0-9]{0,15}$/D', $batch) === 1) {
            $number = (int) $batch;
        } else {
            return null;
        }
        if ($number < 1 || $number > self::MAX_BATCH || $nodeId < 1) {
            return null;
        }
        $type = strtolower($nodeType);
        return Uuid::uuid5(self::NAMESPACE, "{$type}:{$nodeId}:{$ledger}:{$number}")->toString();
    }
}
