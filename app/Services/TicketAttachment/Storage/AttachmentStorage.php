<?php

namespace App\Services\TicketAttachment\Storage;

use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 附件存储驱动。key 是驱动自己生成的完整存储路径 / 对象 key，原样落库，
 * 之后的删除 / 下载都拿库里的 key 直接操作，不再依赖当时的目录或前缀配置。
 */
interface AttachmentStorage
{
    public function driver(): string;

    /** 生成一个新的存储 key（含目录 / 前缀），扩展名由调用方给出 */
    public function newKey(string $extension): string;

    /** 把本地临时文件写入存储。失败抛异常。 */
    public function put(string $key, string $localPath, string $mime): void;

    /** 删除对象；对象不存在视为成功。失败抛异常。 */
    public function delete(string $key): void;

    /** 读取整个对象内容（附件上限 20MB，直接进内存即可）。不存在 / 失败抛异常。 */
    public function get(string $key): string;

    /**
     * 浏览器可直接访问的临时链接（预签名 / 公共 URL）。
     * 返回 null 表示该驱动不支持，由 response() 走后端流式输出。
     */
    public function temporaryUrl(string $key, int $ttl, string $downloadName, bool $inline, string $mime): ?string;

    /** 后端流式输出对象内容 */
    public function response(string $key, string $mime, string $downloadName, bool $inline): SymfonyResponse;

    /** 写入 → 读取 → 删除一个探针对象，用于后台「测试存储连接」。失败抛异常。 */
    public function probe(): void;
}
