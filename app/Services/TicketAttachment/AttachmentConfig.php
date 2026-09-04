<?php

namespace App\Services\TicketAttachment;

/**
 * 工单附件配置快照。
 *
 * 所有键都存放在 v2_settings（admin_setting）。这里集中定义默认值与硬上限，
 * ConfigController / CommController / 上传校验 / 清理任务共用同一份定义，避免各处默认值漂移。
 */
final class AttachmentConfig
{
    public const DRIVER_LOCAL = 'local';
    public const DRIVER_S3 = 's3';

    /**
     * 单文件体积硬上限（MB）。与 config/octane.php 的 package_max_length（32MB）和
     * .docker/usr/local/etc/php/conf.d/zz-xboard-uploads.ini 的 post_max_size（32MB）配套：
     * 后台无论怎么配都越不过这个值，否则请求会在 Swoole / PHP 层被直接掐断，前端只看到网络错误。
     * base64 JSON 形态的上传会膨胀 1/3，20MB 文件约 27MB 请求体，仍在 32MB 之内。
     */
    public const HARD_MAX_SIZE_MB = 20;
    public const HARD_MAX_COUNT = 10;
    /** 待绑定附件的存活时间：上传后一直没随消息发出的，超过该时长由清理任务回收 */
    public const PENDING_TTL = 86400;
    /** 只有这几类图片才会以 inline 方式输出，其余一律 attachment 下载（防 SVG / HTML 型 XSS） */
    public const INLINE_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    public const DEFAULT_ALLOWED_EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip';

    public const DEFAULTS = [
        'ticket_attachment_enable' => 0,
        'ticket_attachment_driver' => self::DRIVER_LOCAL,
        'ticket_attachment_max_size_mb' => 5,
        'ticket_attachment_max_count' => 5,
        'ticket_attachment_allowed_extensions' => self::DEFAULT_ALLOWED_EXTENSIONS,
        'ticket_attachment_daily_quota_mb' => 30,
        'ticket_attachment_retention_days' => 365,
        'ticket_attachment_s3_endpoint' => '',
        'ticket_attachment_s3_region' => 'auto',
        'ticket_attachment_s3_bucket' => '',
        'ticket_attachment_s3_access_key' => '',
        'ticket_attachment_s3_secret_key' => '',
        'ticket_attachment_s3_path_style' => 1,
        'ticket_attachment_s3_prefix' => 'ticket-attachments',
        'ticket_attachment_s3_public_url' => '',
    ];

    /**
     * @param string[] $allowedExtensions 小写、去重后的扩展名
     * @param array{endpoint:string,region:string,bucket:string,access_key:string,secret_key:string,path_style:bool,prefix:string,public_url:string} $s3
     */
    public function __construct(
        public readonly bool $enable,
        public readonly string $driver,
        public readonly int $maxSizeMb,
        public readonly int $maxCount,
        public readonly array $allowedExtensions,
        public readonly int $dailyQuotaMb,
        public readonly int $retentionDays,
        public readonly array $s3,
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

        $maxSize = (int) $get('ticket_attachment_max_size_mb');
        $maxCount = (int) $get('ticket_attachment_max_count');

        return new self(
            enable: (bool) $get('ticket_attachment_enable'),
            driver: $get('ticket_attachment_driver') === self::DRIVER_S3 ? self::DRIVER_S3 : self::DRIVER_LOCAL,
            maxSizeMb: max(1, min(self::HARD_MAX_SIZE_MB, $maxSize)),
            maxCount: max(1, min(self::HARD_MAX_COUNT, $maxCount)),
            allowedExtensions: self::parseExtensions((string) $get('ticket_attachment_allowed_extensions')),
            dailyQuotaMb: max(0, (int) $get('ticket_attachment_daily_quota_mb')),
            retentionDays: max(0, (int) $get('ticket_attachment_retention_days')),
            s3: [
                'endpoint' => rtrim(trim((string) $get('ticket_attachment_s3_endpoint')), '/'),
                'region' => trim((string) $get('ticket_attachment_s3_region')) ?: 'auto',
                'bucket' => trim((string) $get('ticket_attachment_s3_bucket')),
                'access_key' => trim((string) $get('ticket_attachment_s3_access_key')),
                'secret_key' => trim((string) $get('ticket_attachment_s3_secret_key')),
                'path_style' => (bool) $get('ticket_attachment_s3_path_style'),
                'prefix' => trim((string) $get('ticket_attachment_s3_prefix'), " /\t\n\r"),
                'public_url' => rtrim(trim((string) $get('ticket_attachment_s3_public_url')), '/'),
            ],
        );
    }

    /**
     * 解析后台填写的扩展名列表（逗号 / 空格分隔，大小写与前导点不敏感）。
     *
     * @return string[]
     */
    public static function parseExtensions(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            $raw = self::DEFAULT_ALLOWED_EXTENSIONS;
        }
        $list = [];
        foreach (preg_split('/[\s,，;]+/u', strtolower($raw)) ?: [] as $ext) {
            $ext = ltrim(trim($ext), '.');
            if ($ext !== '' && preg_match('/^[a-z0-9]{1,16}$/', $ext)) {
                $list[$ext] = true;
            }
        }
        return array_keys($list);
    }

    public function maxSizeBytes(): int
    {
        return $this->maxSizeMb * 1024 * 1024;
    }

    public function dailyQuotaBytes(): int
    {
        return $this->dailyQuotaMb * 1024 * 1024;
    }

    /**
     * 下发给用户前端的公开部分（不含任何存储凭据）。
     */
    public function toPublicArray(): array
    {
        return [
            'enable' => $this->enable,
            'max_size_mb' => $this->maxSizeMb,
            'max_count' => $this->maxCount,
            'allowed_extensions' => $this->allowedExtensions,
            'daily_quota_mb' => $this->dailyQuotaMb,
        ];
    }

    /**
     * 后台配置页读取的完整形态（键名与 v2_settings 一致）。
     */
    public function toAdminArray(): array
    {
        return [
            'ticket_attachment_enable' => $this->enable,
            'ticket_attachment_driver' => $this->driver,
            'ticket_attachment_max_size_mb' => $this->maxSizeMb,
            'ticket_attachment_max_count' => $this->maxCount,
            'ticket_attachment_allowed_extensions' => implode(',', $this->allowedExtensions),
            'ticket_attachment_daily_quota_mb' => $this->dailyQuotaMb,
            'ticket_attachment_retention_days' => $this->retentionDays,
            'ticket_attachment_s3_endpoint' => $this->s3['endpoint'],
            'ticket_attachment_s3_region' => $this->s3['region'],
            'ticket_attachment_s3_bucket' => $this->s3['bucket'],
            'ticket_attachment_s3_access_key' => $this->s3['access_key'],
            'ticket_attachment_s3_secret_key' => $this->s3['secret_key'],
            'ticket_attachment_s3_path_style' => $this->s3['path_style'],
            'ticket_attachment_s3_prefix' => $this->s3['prefix'],
            'ticket_attachment_s3_public_url' => $this->s3['public_url'],
            // 只读：让后台表单知道上限在哪，不用把常量抄一份到前端
            'ticket_attachment_hard_max_size_mb' => self::HARD_MAX_SIZE_MB,
            'ticket_attachment_hard_max_count' => self::HARD_MAX_COUNT,
        ];
    }
}
