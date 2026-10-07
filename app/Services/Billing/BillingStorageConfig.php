<?php

namespace App\Services\Billing;

/**
 * 收据 / 账单归档的存储与保留策略快照。
 *
 * 所有键都在 v2_settings（admin_setting），后台「收据与账单」设置页可改；
 * 默认值集中在这里，ConfigController / 归档 / 清理任务共用一份定义。
 *
 * 保留策略：
 * - 收据 PDF 超过 billing_receipt_retention_days 天后删文件、留记录（size 置 0），用户再下载时按订单重新渲染；
 * - 账单在对应周期结束 billing_invoice_retention_days 天后连记录一起删（已结清 / 已失效的才删，待付款的不动）；
 * - 0 = 永久保留。
 */
final class BillingStorageConfig
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3 = 's3';

    public const DEFAULTS = [
        'billing_storage_driver' => self::DRIVER_LOCAL,
        'billing_s3_endpoint' => '',
        'billing_s3_region' => 'auto',
        'billing_s3_bucket' => '',
        'billing_s3_access_key' => '',
        'billing_s3_secret_key' => '',
        'billing_s3_path_style' => 1,
        'billing_s3_prefix' => 'billing/documents',
        'billing_receipt_retention_days' => 365,
        'billing_invoice_retention_days' => 90,
    ];

    /**
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string} $s3
     */
    public function __construct(
        public readonly string $driver,
        public readonly array $s3,
        public readonly int $receiptRetentionDays,
        public readonly int $invoiceRetentionDays,
    ) {
    }

    /**
     * @param array $override 覆盖 admin_setting 的键值（后台「测试存储连接」用尚未保存的表单值探测时传入）
     */
    public static function fromSettings(array $override = []): self
    {
        $get = static function (string $key) use ($override) {
            if (array_key_exists($key, $override) && $override[$key] !== null) {
                return $override[$key];
            }
            return admin_setting($key, self::DEFAULTS[$key]);
        };

        return new self(
            driver: $get('billing_storage_driver') === self::DRIVER_S3 ? self::DRIVER_S3 : self::DRIVER_LOCAL,
            s3: [
                'endpoint' => rtrim(trim((string) $get('billing_s3_endpoint')), '/'),
                'region' => trim((string) $get('billing_s3_region')) ?: 'auto',
                'bucket' => trim((string) $get('billing_s3_bucket')),
                'access_key' => trim((string) $get('billing_s3_access_key')),
                'secret_key' => trim((string) $get('billing_s3_secret_key')),
                'path_style' => (bool) $get('billing_s3_path_style'),
                'prefix' => trim((string) $get('billing_s3_prefix'), " /\t\n\r"),
            ],
            receiptRetentionDays: max(0, min(3650, (int) $get('billing_receipt_retention_days'))),
            invoiceRetentionDays: max(0, min(3650, (int) $get('billing_invoice_retention_days'))),
        );
    }

    /** 后台展示用的存储位置：本地目录，或「桶/前缀」 */
    public function location(): string
    {
        if ($this->driver === self::DRIVER_S3) {
            return rtrim($this->s3['bucket'] . '/' . $this->s3['prefix'], '/');
        }
        return 'storage/app/' . Storage\LocalDocumentStore::DIR;
    }

    /**
     * 后台配置页读取的完整形态（键名与 v2_settings 一致）。
     */
    public function toAdminArray(): array
    {
        return [
            'billing_storage_driver' => $this->driver,
            'billing_s3_endpoint' => $this->s3['endpoint'],
            'billing_s3_region' => $this->s3['region'],
            'billing_s3_bucket' => $this->s3['bucket'],
            'billing_s3_access_key' => $this->s3['access_key'],
            'billing_s3_secret_key' => $this->s3['secret_key'],
            'billing_s3_path_style' => $this->s3['path_style'],
            'billing_s3_prefix' => $this->s3['prefix'],
            'billing_receipt_retention_days' => $this->receiptRetentionDays,
            'billing_invoice_retention_days' => $this->invoiceRetentionDays,
        ];
    }
}
