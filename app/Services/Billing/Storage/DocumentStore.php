<?php

namespace App\Services\Billing\Storage;

/**
 * 收据 / 账单 PDF 的存储驱动。key 是驱动自己生成的完整路径 / 对象 key，原样落库（v2_billing_document.path），
 * 之后的读取 / 删除都拿库里的 key 与 disk 直接操作，不再依赖当时的目录或前缀配置。
 */
interface DocumentStore
{
    public function driver(): string;

    /** 该驱动下一份文档的存储 key：<目录或前缀>/<user_id>/<doc_no>.pdf */
    public function keyFor(int $userId, string $docNo): string;

    /** 写入（覆盖）PDF。失败抛异常。 */
    public function put(string $key, string $pdf): void;

    /** 读取 PDF；不存在返回 null，其余失败抛异常。 */
    public function get(string $key): ?string;

    /** 删除；不存在视为成功。失败抛异常。 */
    public function delete(string $key): void;

    /** 写入 → 读回 → 删除一个探针对象，用于后台「测试存储连接」。失败抛异常。 */
    public function probe(): void;
}
