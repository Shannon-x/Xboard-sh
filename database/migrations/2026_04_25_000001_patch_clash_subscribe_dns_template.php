<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $templateNames = ['clash', 'clashmeta', 'stash'];

    public function up(): void
    {
        foreach ($this->templateNames as $name) {
            $content = DB::table('v2_subscribe_templates')
                ->where('name', $name)
                ->value('content');

            if (!is_string($content) || $content === '') {
                continue;
            }

            $patched = $this->patchDnsBlock($content);
            if ($patched === $content) {
                continue;
            }

            DB::table('v2_subscribe_templates')
                ->where('name', $name)
                ->update([
                    'content' => $patched,
                    'updated_at' => now(),
                ]);

            try {
                Cache::store('redis')->forget("subscribe_template:{$name}");
            } catch (Throwable) {
                // Redis may not be available while migrations run in some deploy paths.
            }
        }
    }

    public function down(): void
    {
        // Keep operator-customized subscription templates intact.
    }

    private function patchDnsBlock(string $content): string
    {
        $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (!is_array($lines)) {
            return $content;
        }

        $dnsIndex = null;
        $dnsIndent = 0;
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)dns:\s*(?:#.*)?$/', $line, $matches)) {
                $dnsIndex = $index;
                $dnsIndent = strlen($matches[1]);
                break;
            }
        }

        if ($dnsIndex === null) {
            return $content;
        }

        $blockEnd = count($lines);
        for ($index = $dnsIndex + 1; $index < count($lines); $index++) {
            $line = $lines[$index];
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($indent <= $dnsIndent) {
                $blockEnd = $index;
                break;
            }
        }

        $childIndent = str_repeat(' ', $dnsIndent + 2);
        $hasRespectRules = false;
        $hasProxyServerNameserver = false;
        $proxyServerNameserverIndex = null;
        $useHostsIndex = null;
        $nameserverIndex = null;
        $fallbackIndex = null;

        for ($index = $dnsIndex + 1; $index < $blockEnd; $index++) {
            $line = $lines[$index];
            if (preg_match('/^' . preg_quote($childIndent, '/') . 'respect-rules\s*:/', $line)) {
                $hasRespectRules = true;
            }
            if (preg_match('/^' . preg_quote($childIndent, '/') . 'proxy-server-nameserver\s*:/', $line)) {
                $hasProxyServerNameserver = true;
                $proxyServerNameserverIndex = $index;
            }
            if (preg_match('/^' . preg_quote($childIndent, '/') . 'use-hosts\s*:/', $line)) {
                $useHostsIndex = $index;
            }
            if (preg_match('/^' . preg_quote($childIndent, '/') . 'nameserver\s*:/', $line)) {
                $nameserverIndex = $index;
            }
            if (preg_match('/^' . preg_quote($childIndent, '/') . 'fallback\s*:/', $line)) {
                $fallbackIndex = $index;
            }
        }

        $insertions = [];
        $replacements = [];
        if (!$hasRespectRules) {
            $insertions[] = [
                'index' => $useHostsIndex !== null ? $useHostsIndex + 1 : $dnsIndex + 1,
                'lines' => [
                    $childIndent . 'respect-rules: true',
                ],
            ];
        }

        $proxyServerNameserverLines = [
            $childIndent . 'proxy-server-nameserver:',
            $childIndent . '  - https://doh.pub/dns-query',
            $childIndent . '  - https://dns.alidns.com/dns-query',
        ];

        if ($hasProxyServerNameserver && $proxyServerNameserverIndex !== null) {
            $proxyServerNameserverEnd = $blockEnd;
            for ($index = $proxyServerNameserverIndex + 1; $index < $blockEnd; $index++) {
                if (preg_match('/^' . preg_quote($childIndent, '/') . '[A-Za-z0-9_-]+\s*:/', $lines[$index])) {
                    $proxyServerNameserverEnd = $index;
                    break;
                }
            }

            $currentBlock = array_slice(
                $lines,
                $proxyServerNameserverIndex,
                $proxyServerNameserverEnd - $proxyServerNameserverIndex
            );

            if ($currentBlock !== $proxyServerNameserverLines) {
                $replacements[] = [
                    'index' => $proxyServerNameserverIndex,
                    'length' => $proxyServerNameserverEnd - $proxyServerNameserverIndex,
                    'lines' => $proxyServerNameserverLines,
                ];
            }
        } else {
            $insertions[] = [
                'index' => $nameserverIndex ?? $fallbackIndex ?? $blockEnd,
                'lines' => $proxyServerNameserverLines,
            ];
        }

        usort($replacements, fn($left, $right) => $right['index'] <=> $left['index']);
        foreach ($replacements as $replacement) {
            array_splice($lines, $replacement['index'], $replacement['length'], $replacement['lines']);
        }

        usort($insertions, fn($left, $right) => $right['index'] <=> $left['index']);
        foreach ($insertions as $insertion) {
            array_splice($lines, $insertion['index'], 0, $insertion['lines']);
        }

        return implode($newline, $lines);
    }
};
