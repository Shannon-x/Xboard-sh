<?php

namespace App\Services\Billing\Storage;

use App\Services\Billing\BillingStorageConfig;
use App\Services\ObjectStorage\S3ObjectClient;

final class DocumentStoreFactory
{
    /**
     * @param string|null $driver 不传则用当前配置的驱动；读 / 删旧文件时传库里记录的 disk
     */
    public static function make(BillingStorageConfig $config, ?string $driver = null): DocumentStore
    {
        return match ($driver ?? $config->driver) {
            BillingStorageConfig::DRIVER_S3 => new S3DocumentStore(new S3ObjectClient($config->s3), $config->s3['prefix']),
            default => LocalDocumentStore::make(),
        };
    }
}
