<?php

/**
 * Build-time guard: every PHP extension the runtime depends on must be loaded.
 *
 * Since 2026-10-21 the image is built FROM the official php image with Swoole, redis,
 * pdo_mysql and sockets compiled in our own Dockerfile (previously they came pre-built in
 * phpswoole/swoole). composer check-platform-reqs only knows the extensions composer.json
 * declares; Octane's Swoole server, the phpredis cache/queue driver and the MySQL driver
 * are runtime requirements it cannot see. A missing one would build fine and crash at
 * octane:start, so assert them here where the build fails loudly instead.
 */

$required = [
    'swoole',     // Octane server (config/octane.php OCTANE_SERVER=swoole)
    'redis',      // phpredis: cache, session, queue, Horizon
    'pdo_mysql',  // database driver
    'sockets',    // Swoole / Workerman
    'pcntl',      // Horizon, Octane workers
    'bcmath',     // money / traffic arithmetic
    'zip',        // mpdf, backups
    'gd',         // QR codes, mpdf
    'openssl',
    'curl',
    'mbstring',
    'tokenizer',
    'fileinfo',
    'dom',
    'xml',
];

$missing = array_values(array_filter($required, static fn (string $ext): bool => ! extension_loaded($ext)));

printf("[runtime-extensions] PHP %s, Swoole %s\n", PHP_VERSION, phpversion('swoole') ?: 'missing');

if ($missing !== []) {
    fwrite(STDERR, '[runtime-extensions] FATAL: missing PHP extensions: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "[runtime-extensions] OK — all required extensions loaded\n";
exit(0);
