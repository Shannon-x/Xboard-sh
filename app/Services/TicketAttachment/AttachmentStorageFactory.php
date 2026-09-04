<?php

namespace App\Services\TicketAttachment;

use App\Services\TicketAttachment\Storage\AttachmentStorage;
use App\Services\TicketAttachment\Storage\LocalAttachmentStorage;
use App\Services\TicketAttachment\Storage\S3AttachmentStorage;

final class AttachmentStorageFactory
{
    /**
     * @param string|null $driver 不传则用当前配置的驱动；删除旧文件时传库里记录的驱动
     */
    public static function make(AttachmentConfig $config, ?string $driver = null): AttachmentStorage
    {
        return match ($driver ?? $config->driver) {
            AttachmentConfig::DRIVER_S3 => new S3AttachmentStorage($config->s3),
            default => LocalAttachmentStorage::make(),
        };
    }
}
