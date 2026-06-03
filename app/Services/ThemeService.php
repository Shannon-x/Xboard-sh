<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Http\UploadedFile;
use Exception;
use ZipArchive;

class ThemeService
{
    private const SYSTEM_THEME_DIR = 'theme/';
    private const USER_THEME_DIR = '/storage/theme/';
    private const CONFIG_FILE = 'config.json';
    private const SETTING_PREFIX = 'theme_';
    private const SYSTEM_THEMES = ['Xboard', 'v2board'];

    public function __construct()
    {
        $this->registerThemeViewPaths();
    }

    /**
     * Register theme view paths
     */
    private function registerThemeViewPaths(): void
    {
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            View::addNamespace('theme', $systemPath);
        }

        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            View::prependNamespace('theme', $userPath);
        }
    }

    /**
     * Get theme view path
     */
    public function getThemeViewPath(string $theme): ?string
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }
        return $themePath . '/dashboard.blade.php';
    }

    /**
     * Get all available themes
     */
    public function getList(): array
    {
        $themes = [];

        // 获取系统主题
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            $themes = $this->getThemesFromPath($systemPath, false);
        }

        // 获取用户主题
        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            $themes = array_merge($themes, $this->getThemesFromPath($userPath, true));
        }

        return $themes;
    }

    /**
     * Get themes from specified path
     */
    private function getThemesFromPath(string $path, bool $canDelete): array
    {
        return collect(File::directories($path))
            ->mapWithKeys(function ($dir) use ($canDelete) {
                $name = basename($dir);
                if (
                    !File::exists($dir . '/' . self::CONFIG_FILE) ||
                    !File::exists($dir . '/dashboard.blade.php')
                ) {
                    return [];
                }
                $config = $this->readConfigFile($name);
                if (!$config) {
                    return [];
                }

                $config['can_delete'] = $canDelete && $name !== admin_setting('current_theme');
                $config['is_system'] = !$canDelete;
                return [$name => $config];
            })->toArray();
    }

    /**
     * Upload new theme
     */
    public function upload(UploadedFile $file): bool
    {
        $zip = new ZipArchive;
        $zipOpened = false;
        $tmpPath = storage_path('tmp/' . uniqid());

        try {
            if ($zip->open($file->path()) !== true) {
                throw new Exception('Invalid theme package');
            }
            $zipOpened = true;

            $configEntry = collect(range(0, $zip->numFiles - 1))
                ->map(fn($i) => $zip->getNameIndex($i))
                ->first(fn($name) => basename($name) === self::CONFIG_FILE);

            if (!$configEntry) {
                throw new Exception('Theme config file not found');
            }

            $this->extractZipSafely($zip, $tmpPath);

            $sourcePath = $tmpPath . '/' . rtrim(dirname($configEntry), '.');
            $configFile = $sourcePath . '/' . self::CONFIG_FILE;

            if (!File::exists($configFile)) {
                throw new Exception('Theme config file not found');
            }

            $config = json_decode(File::get($configFile), true);
            if (empty($config['name'])) {
                throw new Exception('Theme name not configured');
            }

            if (!$this->isSafeThemeName($config['name'])) {
                throw new Exception('Invalid theme name');
            }

            if (in_array($config['name'], self::SYSTEM_THEMES)) {
                throw new Exception('Cannot upload theme with same name as system theme');
            }

            if (!File::exists($sourcePath . '/dashboard.blade.php')) {
                throw new Exception('Missing required theme file: dashboard.blade.php');
            }

            $userThemePath = base_path(self::USER_THEME_DIR);
            if (!File::exists($userThemePath)) {
                File::makeDirectory($userThemePath, 0755, true);
            }

            $targetPath = $userThemePath . $config['name'];
            if (File::exists($targetPath)) {
                $oldConfigFile = $targetPath . '/config.json';
                if (!File::exists($oldConfigFile)) {
                    throw new Exception('Existing theme missing config file');
                }
                $oldConfig = json_decode(File::get($oldConfigFile), true);
                $oldVersion = $oldConfig['version'] ?? '0.0.0';
                $newVersion = $config['version'] ?? '0.0.0';
                if (version_compare($newVersion, $oldVersion, '>')) {
                    $this->cleanupThemeFiles($config['name']);
                    File::deleteDirectory($targetPath);
                    File::copyDirectory($sourcePath, $targetPath);
                    // 更新主题时保留用户配置
                    $this->initConfig($config['name'], true);
                    return true;
                } else {
                    throw new Exception('Theme exists and not a newer version');
                }
            }

            File::copyDirectory($sourcePath, $targetPath);
            $this->initConfig($config['name']);

            return true;

        } catch (Exception $e) {
            throw $e;
        } finally {
            if ($zipOpened) {
                $zip->close();
            }
            if (File::exists($tmpPath)) {
                File::deleteDirectory($tmpPath);
            }
        }
    }

    /**
     * Switch theme
     */
    public function switch(string|null $theme): bool
    {
        if ($theme === null) {
            return true;
        }

        $currentTheme = admin_setting('current_theme');

        try {
            $themePath = $this->getThemePath($theme);
            if (!$themePath) {
                throw new Exception('Theme not found');
            }

            if (!File::exists($this->getThemeViewPath($theme))) {
                throw new Exception('Theme view file not found');
            }

            if ($currentTheme && $currentTheme !== $theme) {
                $this->cleanupThemeFiles($currentTheme);
            }

            $targetPath = public_path('theme/' . $theme);
            // 只复制白名单扩展的静态资源到 public/，不再 File::copyDirectory 全量复制。
            // 主题包内若含 .php / .htaccess / .user.ini 等会被 PHP-FPM 解析的文件，
            // 全量复制后即可通过 https://host/theme/<name>/pwn.php 执行（post-auth admin RCE）。
            if (!self::copyThemeAssets($themePath, $targetPath)) {
                throw new Exception('Failed to copy theme files');
            }

            admin_setting(['current_theme' => $theme]);
            return true;

        } catch (Exception $e) {
            Log::error('Theme switch failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete theme
     */
    public function delete(string $theme): bool
    {
        try {
            if (!$this->isSafeThemeName($theme)) {
                throw new Exception('Invalid theme name');
            }

            if (in_array($theme, self::SYSTEM_THEMES)) {
                throw new Exception('System theme cannot be deleted');
            }

            if ($theme === admin_setting('current_theme')) {
                throw new Exception('Current theme cannot be deleted');
            }

            $themePath = base_path(self::USER_THEME_DIR . $theme);
            if (!File::exists($themePath)) {
                throw new Exception('Theme not found');
            }

            $this->cleanupThemeFiles($theme);
            File::deleteDirectory($themePath);
            admin_setting([self::SETTING_PREFIX . $theme => null]);
            return true;

        } catch (Exception $e) {
            Log::error('Theme deletion failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if theme exists
     */
    public function exists(string $theme): bool
    {
        return $this->getThemePath($theme) !== null;
    }

    /**
     * Get theme path
     */
    public function getThemePath(string $theme): ?string
    {
        if (!$this->isSafeThemeName($theme)) {
            return null;
        }

        $systemPath = base_path(self::SYSTEM_THEME_DIR . $theme);
        if (File::exists($systemPath)) {
            return $systemPath;
        }

        $userPath = base_path(self::USER_THEME_DIR . $theme);
        if (File::exists($userPath)) {
            return $userPath;
        }

        return null;
    }

    private function isSafeThemeName(string $theme): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $theme);
    }

    private function extractZipSafely(ZipArchive $zip, string $targetPath): void
    {
        File::ensureDirectoryExists($targetPath, 0755, true);

        $targetRoot = realpath($targetPath);
        if ($targetRoot === false) {
            throw new Exception('Invalid extract path');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entry);
            if ($this->isUnsafeZipEntry($zip, $i, $normalized)) {
                throw new Exception('Unsafe path in theme package');
            }

            $destination = $targetRoot . DIRECTORY_SEPARATOR . $normalized;
            $parent = dirname($destination);
            File::ensureDirectoryExists($parent, 0755, true);

            $realParent = realpath($parent);
            $rootPrefix = rtrim($targetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($realParent === false || ($realParent !== $targetRoot && !str_starts_with($realParent, $rootPrefix))) {
                throw new Exception('Unsafe path in theme package');
            }

            if (str_ends_with($normalized, '/')) {
                File::ensureDirectoryExists($destination, 0755, true);
                continue;
            }

            if (!$zip->extractTo($targetRoot, $entry)) {
                throw new Exception('Failed to extract theme package');
            }
        }
    }

    private function isUnsafeZipEntry(ZipArchive $zip, int $index, string $normalized): bool
    {
        if (
            $normalized === '' ||
            str_contains($normalized, "\0") ||
            str_starts_with($normalized, '/') ||
            preg_match('#^[A-Za-z]:/#', $normalized) ||
            preg_match('#(^|/)\.\.(/|$)#', $normalized)
        ) {
            return true;
        }

        // 主题包不允许携带任何会被 PHP-FPM / web server 解释执行的文件 ——
        // switch() 会把主题包整体 copyDirectory 到 public_path('theme/{name}')，
        // 任何含 pwn.php 的合法主题包切换后即可通过 https://host/theme/<name>/pwn.php 命中 RCE。
        // 黑名单覆盖：直接的 PHP 解释器后缀 + PHP 配置文件 + nginx/Apache 解析劫持文件。
        if (preg_match('/\.(php\d?|phtml|phar|phps|pht|inc|hphp)$/i', $normalized)) {
            return true;
        }
        $basename = basename($normalized);
        if (strcasecmp($basename, '.htaccess') === 0 || strcasecmp($basename, '.user.ini') === 0) {
            return true;
        }

        if (method_exists($zip, 'getExternalAttributesIndex')) {
            $opsys = 0;
            $attr = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attr)) {
                $mode = ($attr >> 16) & 0170000;
                if ($mode === 0120000) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 把主题目录里的静态资源（仅限白名单扩展）复制到 public/theme/<name>。
     * Blade view 走 namespace 渲染（registerThemeViewPaths），不需要 public 端可执行的 .php。
     */
    private static function copyThemeAssets(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        $allowedExts = [
            'css', 'js', 'mjs', 'map', 'json', 'html', 'htm',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'mp3', 'mp4', 'webm', 'ogg', 'wav',
            'txt', 'md',
            // 预压缩资源：Vite/webpack 常生成 umi.js.gz / umi.js.br 与原文件配对，
            // 由 nginx gzip_static / brotli_static 模块直接返回给客户端。漏掉的话
            // 切换主题后 CDN/反代仍按原始 .js 重新压缩，浪费 CPU 并丢失预压缩收益。
            // 这些是静态二进制，不会被 PHP-FPM 解释执行，加入白名单安全。
            'gz', 'br', 'zst',
        ];
        File::ensureDirectoryExists($dst, 0755, true);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            $relative = ltrim(str_replace($src, '', $entry->getPathname()), DIRECTORY_SEPARATOR);
            $target = $dst . DIRECTORY_SEPARATOR . $relative;
            if ($entry->isDir()) {
                File::ensureDirectoryExists($target, 0755, true);
                continue;
            }
            $ext = strtolower($entry->getExtension());
            // 跳过任何会被 PHP-FPM/web server 解释的文件；扩展名白名单兜底
            if (!in_array($ext, $allowedExts, true)) {
                continue;
            }
            // 防止符号链接逃逸目录
            if ($entry->isLink()) {
                continue;
            }
            File::copy($entry->getPathname(), $target);
        }
        return true;
    }

    /**
     * Get theme config
     */
    public function getConfig(string $theme): ?array
    {
        $config = admin_setting(self::SETTING_PREFIX . $theme);
        if ($config === null) {
            $this->initConfig($theme);
            $config = admin_setting(self::SETTING_PREFIX . $theme);
        }
        return $config;
    }

    /**
     * Update theme config
     */
    public function updateConfig(string $theme, array $config): bool
    {
        try {
            if (!$this->getThemePath($theme)) {
                throw new Exception('Theme not found');
            }

            $schema = $this->readConfigFile($theme);
            if (!$schema) {
                throw new Exception('Invalid theme config file');
            }

            $validFields = collect($schema['configs'] ?? [])->pluck('field_name')->toArray();
            $validConfig = collect($config)
                ->only($validFields)
                ->toArray();

            $currentConfig = $this->getConfig($theme) ?? [];
            $newConfig = array_merge($currentConfig, $validConfig);

            admin_setting([self::SETTING_PREFIX . $theme => $newConfig]);
            return true;

        } catch (Exception $e) {
            Log::error('Config update failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Read theme config file
     */
    private function readConfigFile(string $theme): ?array
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }

        $file = $themePath . '/' . self::CONFIG_FILE;
        return File::exists($file) ? json_decode(File::get($file), true) : null;
    }

    /**
     * Clean up theme files including public directory
     */
    public function cleanupThemeFiles(string $theme): void
    {
        try {
            if (!$this->isSafeThemeName($theme)) {
                throw new Exception('Invalid theme name');
            }

            $publicThemePath = public_path('theme/' . $theme);
            if (File::exists($publicThemePath)) {
                File::deleteDirectory($publicThemePath);
                Log::info('Cleaned up public theme files', ['theme' => $theme, 'path' => $publicThemePath]);
            }

            $cacheKey = "theme_{$theme}_assets";
            if (cache()->has($cacheKey)) {
                cache()->forget($cacheKey);
                Log::info('Cleaned up theme cache', ['theme' => $theme, 'cache_key' => $cacheKey]);
            }

        } catch (Exception $e) {
            Log::warning('Failed to cleanup theme files', [
                'theme' => $theme,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Force refresh current theme public files
     */
    public function refreshCurrentTheme(): bool
    {
        try {
            $currentTheme = admin_setting('current_theme');
            if (!$currentTheme) {
                return false;
            }

            $this->cleanupThemeFiles($currentTheme);

            $themePath = $this->getThemePath($currentTheme);
            if (!$themePath) {
                throw new Exception('Current theme path not found');
            }

            $targetPath = public_path('theme/' . $currentTheme);
            // 同 switch()：白名单复制，禁止把 .php / .htaccess 等可执行文件搬到 public/
            if (!self::copyThemeAssets($themePath, $targetPath)) {
                throw new Exception('Failed to copy theme files');
            }

            Log::info('Refreshed current theme files', ['theme' => $currentTheme]);
            return true;

        } catch (Exception $e) {
            Log::error('Failed to refresh current theme', [
                'theme' => $currentTheme,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Initialize theme config
     * 
     * @param string $theme 主题名称
     * @param bool $preserveExisting 是否保留现有配置（更新主题时使用）
     */
    private function initConfig(string $theme, bool $preserveExisting = false): void
    {
        $config = $this->readConfigFile($theme);
        if (!$config) {
            return;
        }

        $defaults = collect($config['configs'] ?? [])
            ->mapWithKeys(fn($col) => [$col['field_name'] => $col['default_value'] ?? ''])
            ->toArray();

        if ($preserveExisting) {
            $existingConfig = admin_setting(self::SETTING_PREFIX . $theme) ?? [];
            $mergedConfig = array_merge($defaults, $existingConfig);
            admin_setting([self::SETTING_PREFIX . $theme => $mergedConfig]);
        } else {
            admin_setting([self::SETTING_PREFIX . $theme => $defaults]);
        }
    }
}
