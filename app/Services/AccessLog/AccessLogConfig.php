<?php

namespace App\Services\AccessLog;

/**
 * 用户访问记录（Rust 节点逐条连接记录 → 面板 → S3）的配置快照。
 *
 * 所有键存放在 v2_settings（admin_setting）。开关、批次间隔和保留天数下发给节点（握手响应），
 * S3 凭据只在面板使用。和工单附件一样走面板自带的 SigV4 客户端，不依赖 aws-sdk。
 */
final class AccessLogConfig
{
    public const DEFAULTS = [
        'access_log_enable' => 0,
        'access_log_interval' => 300,
        'access_log_retention_days' => 30,
        'access_log_s3_endpoint' => '',
        'access_log_s3_region' => 'auto',
        'access_log_s3_bucket' => '',
        'access_log_s3_access_key' => '',
        'access_log_s3_secret_key' => '',
        'access_log_s3_path_style' => 1,
        'access_log_s3_prefix' => 'access-logs',
    ];

    /** 节点两次封批的间隔（秒）可选范围，与节点端的夹取范围一致 */
    public const MIN_INTERVAL = 60;
    public const MAX_INTERVAL = 3600;
    public const MAX_RETENTION_DAYS = 365;
    /** 导出的 CSV 留多久（天），到期由每日清理删除 */
    public const EXPORT_DAYS = 7;

    /**
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string} $s3
     */
    public function __construct(
        public readonly bool $enable,
        public readonly int $interval,
        public readonly int $retentionDays,
        public readonly array $s3,
    ) {
    }

    public static function fromSettings(array $override = []): self
    {
        $get = static function (string $key) use ($override) {
            if (array_key_exists($key, $override) && $override[$key] !== null) {
                return $override[$key];
            }
            return admin_setting($key, self::DEFAULTS[$key]);
        };

        return new self(
            enable: (bool) $get('access_log_enable'),
            interval: max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, (int) $get('access_log_interval'))),
            retentionDays: max(1, min(self::MAX_RETENTION_DAYS, (int) $get('access_log_retention_days'))),
            s3: [
                'endpoint' => rtrim(trim((string) $get('access_log_s3_endpoint')), '/'),
                'region' => trim((string) $get('access_log_s3_region')) ?: 'auto',
                'bucket' => trim((string) $get('access_log_s3_bucket')),
                'access_key' => trim((string) $get('access_log_s3_access_key')),
                'secret_key' => trim((string) $get('access_log_s3_secret_key')),
                'path_style' => (bool) $get('access_log_s3_path_style'),
                'prefix' => trim((string) $get('access_log_s3_prefix'), " /\t\n\r") ?: 'access-logs',
            ],
        );
    }

    public function storageConfigured(): bool
    {
        return $this->s3['bucket'] !== '' && $this->s3['access_key'] !== '' && $this->s3['secret_key'] !== '';
    }

    /** 节点只在开关打开且存储已配好时记录，免得记下来却无处可存 */
    public function recording(): bool
    {
        return $this->enable && $this->storageConfigured();
    }

    /** 握手响应里给节点的部分 */
    public function forNode(): array
    {
        return [
            'enabled' => $this->recording(),
            'interval' => $this->interval,
        ];
    }

    public function toAdminArray(): array
    {
        return [
            'access_log_enable' => $this->enable,
            'access_log_interval' => $this->interval,
            'access_log_retention_days' => $this->retentionDays,
            'access_log_s3_endpoint' => $this->s3['endpoint'],
            'access_log_s3_region' => $this->s3['region'],
            'access_log_s3_bucket' => $this->s3['bucket'],
            'access_log_s3_access_key' => $this->s3['access_key'],
            'access_log_s3_secret_key' => $this->s3['secret_key'],
            'access_log_s3_path_style' => $this->s3['path_style'],
            'access_log_s3_prefix' => $this->s3['prefix'],
        ];
    }
}
