<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 收据 / 账单 / 提现邮件抬头里的品牌 logo。
 *
 * 来源：后台 billing_logo（完整 URL，或相对用户端 app_url 的站内路径如 /brand/logo.png）；
 * 没配就用站点 logo（admin_setting('logo')，用户端和邮件主题已经在用的那张）。
 *
 * 邮件里直接引用 URL，由邮件客户端自己加载；PDF 必须把图嵌进文件，所以这里下载一次、
 * 用 gd 等比缩成最长边 240px 的 PNG（webp / jpg / gif 都能转；gd 是 mPDF 的硬依赖，镜像里带 webp 支持），
 * 缓存在 storage/app/billing/logo/，7 天后重新拉；SVG 原样嵌入（mPDF 自带 SVG 解析）。
 * 拉不到或格式不认识时返回 null：PDF 退回纯文字抬头，邮件里的 <img> 不受影响。
 */
final class BrandLogo
{
    private const TTL = 7 * 86400;
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_SIDE = 240;   // PDF 里按 14mm 高显示，240px 已超过 400 dpi，再大只是白白撑大每份 PDF

    /** 邮件 <img> 用的绝对 URL；没配 logo 时 null。 */
    public static function url(): ?string
    {
        $raw = self::setting();
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        $base = rtrim((string) admin_setting('app_url', ''), '/');
        return $base !== '' ? $base . $raw : null;
    }

    /** 嵌进 PDF 的 data: URI（PNG 或 SVG）；取不到时 null。 */
    public static function dataUri(): ?string
    {
        $asset = self::resolve();
        return $asset ? self::encode($asset[1], $asset[0]) : null;
    }

    /**
     * 邮件里 <img> 的像素尺寸（高固定 40px，宽按比例）：Outlook 不认 CSS 的 width:auto，不给 width 属性会按原图尺寸撑开。
     *
     * @return array{width: int, height: int}|null SVG 或还没拉到图时 null，模板就只给 height
     */
    public static function mailSize(int $height = 40): ?array
    {
        $asset = self::resolve();
        if (!$asset || $asset[0] !== 'png') {
            return null;
        }
        $size = @getimagesizefromstring($asset[1]);
        if (!$size || $size[1] <= 0) {
            return null;
        }
        return ['width' => max(1, (int) round($size[0] * $height / $size[1])), 'height' => $height];
    }

    private static function setting(): string
    {
        $raw = trim((string) admin_setting('billing_logo', ''));
        if ($raw === '') {
            $raw = trim((string) admin_setting('logo', ''));
        }
        // 只认 http(s) 和以 / 开头的站内路径，别的（比如 data: 或裸文件名）当没配
        return ($raw !== '' && (preg_match('#^https?://#i', $raw) || $raw[0] === '/')) ? $raw : '';
    }

    /**
     * 可嵌入的 logo：优先新鲜缓存 → 下载并转换 → 过期缓存。
     *
     * @return array{0: string, 1: string}|null [扩展名 png|svg, 字节]
     */
    private static function resolve(): ?array
    {
        $url = self::url();
        if ($url === null) {
            return null;
        }
        $stem = storage_path('app/billing/logo/' . sha1($url));
        if ($cached = self::cached($stem, false)) {
            return $cached;
        }
        $bytes = self::fetch($url);
        $asset = $bytes === null ? null : self::normalize($bytes);
        if ($asset === null) {
            return self::cached($stem, true);   // 这次没拉到：过期缓存也比空着强
        }
        if (!is_dir(dirname($stem))) {
            @mkdir(dirname($stem), 0775, true);
        }
        @file_put_contents("$stem.{$asset[0]}", $asset[1]);
        @unlink($stem . ($asset[0] === 'png' ? '.svg' : '.png'));
        return $asset;
    }

    /** @return array{0: string, 1: string}|null */
    private static function cached(string $stem, bool $allowStale): ?array
    {
        foreach (['png', 'svg'] as $ext) {
            $path = "$stem.$ext";
            if (is_file($path) && ($allowStale || filemtime($path) > time() - self::TTL)) {
                $bytes = @file_get_contents($path);
                return $bytes ? [$ext, $bytes] : null;
            }
        }
        return null;
    }

    private static function fetch(string $url): ?string
    {
        // 站内路径且文件就在本机 public/ 下（后端与用户端同域部署时）：不用绕 HTTP
        $raw = self::setting();
        if ($raw !== '' && $raw[0] === '/') {
            $local = public_path(ltrim((string) strtok($raw, '?'), '/'));
            if (is_file($local)) {
                return @file_get_contents($local) ?: null;
            }
        }
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($url);
            $body = $response->ok() ? $response->body() : '';
            return ($body !== '' && strlen($body) <= self::MAX_BYTES) ? $body : null;
        } catch (\Throwable $e) {
            Log::warning('[billing] 拉取品牌 logo 失败，PDF 用纯文字抬头', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 转成 PDF 能嵌的格式：SVG 原样；位图经 gd 等比缩到最长边 MAX_SIDE 的 PNG（保留透明）。
     *
     * @return array{0: string, 1: string}|null
     */
    private static function normalize(string $bytes): ?array
    {
        if (preg_match('/<svg[\s>]/i', substr($bytes, 0, 4096))) {
            return ['svg', $bytes];
        }
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_SIDE / max($w, $h, 1));
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
        imagedestroy($src);
        ob_start();
        imagepng($dst, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($dst);
        return $png !== '' ? ['png', $png] : null;
    }

    private static function encode(string $bytes, string $ext): string
    {
        return 'data:image/' . ($ext === 'svg' ? 'svg+xml' : 'png') . ';base64,' . base64_encode($bytes);
    }
}
