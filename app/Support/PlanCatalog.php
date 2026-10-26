<?php

namespace App\Support;

/**
 * 套餐目录标签：套餐页按运营商分组、分段、把同一产品的几个批次合成一张卡，靠后台给套餐打的前缀标签：
 *
 *   carrier:美国 AT&T                         运营商（带国家）
 *   segment:basic|static|dynamic|global|custom 分段：普通上网 / 静态 IP / 动态 IP / 日本·星链·全球 / 定制拼车
 *   family:美国 AT&T 静态家宽 · 基础版          合并成一张卡的键，也是这张卡的标题
 *   batch:第 3 车                              批次名（卡上「批次」下拉里显示）
 *
 * 冒号半角全角都认，键不分大小写。不加数据库字段：标签在后台套餐编辑里本来就能改。
 * 这几类标签只给新前端用：用户端接口把它们从 tags 里拿掉、解析成 catalog，旧前端看到的 tags 不变。
 */
final class PlanCatalog
{
    public const KEYS = ['carrier', 'segment', 'family', 'batch'];
    public const SEGMENTS = ['basic', 'static', 'dynamic', 'global', 'custom'];
    private const SEGMENT_ALIASES = [
        '普通上网' => 'basic', '上网' => 'basic', '静态' => 'static', '静态ip' => 'static',
        '动态' => 'dynamic', '动态ip' => 'dynamic', '全球' => 'global', '定制' => 'custom', '拼车' => 'custom',
    ];
    private const MAX_LENGTH = 64;

    /**
     * @param mixed $tags 套餐 tags 原值
     * @return array{tags: array|null, catalog: array{carrier: string|null, segment: string|null, family: string|null, family_name: string|null, batch: string|null}|null}
     */
    public static function split(mixed $tags): array
    {
        if (!is_array($tags)) {
            return ['tags' => $tags, 'catalog' => null];
        }
        $catalog = array_fill_keys(self::KEYS, null);
        $public = [];
        $found = false;
        foreach ($tags as $tag) {
            $parsed = is_string($tag) ? self::parseTag($tag) : null;
            if ($parsed === null) {
                $public[] = $tag;
                continue;
            }
            [$key, $value] = $parsed;
            $found = true;
            // 同一个键写了两遍：留第一个，与后台列表里看到的顺序一致
            if ($catalog[$key] === null && $value !== null) {
                $catalog[$key] = $value;
            }
        }
        if (!$found) {
            return ['tags' => $tags, 'catalog' => null];
        }
        return ['tags' => $public, 'catalog' => [
            'carrier' => $catalog['carrier'],
            'segment' => $catalog['segment'],
            'family' => $catalog['family'],
            'family_name' => $catalog['family'],
            'batch' => $catalog['batch'],
        ]];
    }

    /** 只要合并键：到货提醒按它找同一产品的其它批次（开新车也算到货）。 */
    public static function family(mixed $tags): ?string
    {
        return self::split($tags)['catalog']['family'] ?? null;
    }

    /** @return array{0: string, 1: string|null}|null 不是目录标签返回 null */
    private static function parseTag(string $tag): ?array
    {
        if (!preg_match('/^\s*([a-z]+)\s*[:：]\s*(.*?)\s*$/iu', $tag, $m)) {
            return null;
        }
        $key = strtolower($m[1]);
        if (!in_array($key, self::KEYS, true)) {
            return null;
        }
        $value = mb_substr($m[2], 0, self::MAX_LENGTH);
        if ($value === '') {
            return [$key, null];
        }
        if ($key === 'segment') {
            $normalized = mb_strtolower(str_replace(' ', '', $value));
            $value = in_array($normalized, self::SEGMENTS, true) ? $normalized : (self::SEGMENT_ALIASES[$normalized] ?? null);
        }
        return [$key, $value];
    }
}
