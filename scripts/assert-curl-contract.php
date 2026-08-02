<?php

/**
 * Guards against the "defined but unusable cURL option" failure class.
 *
 * Background (2026-08-02 production outage):
 * Some Swoole builds ship an embedded swoole_library that unconditionally runs
 *
 *     !defined('CURLOPT_PREREQFUNCTION') && define('CURLOPT_PREREQFUNCTION', 20312);
 *
 * so its coroutine cURL hook can support the option. On PHP < 8.4 that define is a
 * lie: native curl_setopt() has no such option and throws
 *
 *     ValueError: curl_setopt(): Argument #2 ($option) is not a valid cURL option
 *
 * Guzzle's CurlFactory::release() guards that call with defined() only, which the
 * userland constant satisfies. release() runs on every handle teardown, so EVERY
 * outbound HTTP request throws — payments, Telegram, webhooks, all of it. The
 * request itself succeeds; the exception is raised during cleanup.
 *
 * PHP 8.4 is the first version whose ext/curl actually implements the option, so
 * the guard below is a no-op there and on any Swoole build that behaves.
 *
 * This script does two things, in order:
 *   1. Hardens Guzzle's guard with a PHP version check (idempotent, best effort).
 *   2. Asserts the real teardown path works, by driving a connection that is
 *      guaranteed to be refused. That exercises finishError() -> release() —
 *      the exact code path that broke — without needing network access.
 *
 * Step 2 is the authoritative gate: if it fails, the build fails.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. Harden Guzzle's guard (best effort — step 2 is what actually gates)
// ---------------------------------------------------------------------------

$curlFactory = $root . '/vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php';

if (is_file($curlFactory)) {
    $source = file_get_contents($curlFactory);

    $needle = "if (\\defined('CURLOPT_PREREQFUNCTION')) {";
    $patched = "if (\\defined('CURLOPT_PREREQFUNCTION') && \\PHP_VERSION_ID >= 80400) {";

    if (str_contains($source, $needle)) {
        file_put_contents($curlFactory, str_replace($needle, $patched, $source));
        echo "[curl-contract] hardened Guzzle CURLOPT_PREREQFUNCTION guard\n";
    } elseif (str_contains($source, $patched)) {
        echo "[curl-contract] Guzzle guard already hardened\n";
    } else {
        echo "[curl-contract] Guzzle guard not present (upstream changed?) — relying on the assertion below\n";
    }
} else {
    echo "[curl-contract] Guzzle not installed, skipping patch\n";
}

// ---------------------------------------------------------------------------
// 2. Report any userland cURL constants the native curl_setopt() rejects
// ---------------------------------------------------------------------------

$landmines = [];

foreach (get_defined_constants(true)['user'] ?? [] as $name => $value) {
    if (! str_starts_with($name, 'CURLOPT_') || ! is_int($value)) {
        continue;
    }

    $handle = curl_init();

    try {
        @curl_setopt($handle, $value, null);
    } catch (Throwable $e) {
        $landmines[] = $name;
    } finally {
        curl_close($handle);
    }
}

if ($landmines !== []) {
    printf(
        "[curl-contract] WARNING: userland cURL options rejected by native curl_setopt(): %s\n",
        implode(', ', $landmines)
    );
    echo "[curl-contract] any library guarding on defined() alone can break on these\n";
}

// ---------------------------------------------------------------------------
// 3. Assert the real Guzzle teardown path (authoritative)
// ---------------------------------------------------------------------------

// Port 1 on loopback refuses immediately: no network required, and the refusal
// routes through CurlFactory::finishError() -> release(), which is where the
// offending curl_setopt() lives.
try {
    (new GuzzleHttp\Client())->get('http://127.0.0.1:1/', ['timeout' => 2]);
} catch (GuzzleHttp\Exception\ConnectException $e) {
    printf(
        "[curl-contract] OK — Guzzle cURL teardown clean on PHP %s (libcurl %s)\n",
        PHP_VERSION,
        curl_version()['version']
    );
    exit(0);
} catch (ValueError $e) {
    fwrite(STDERR, "[curl-contract] FATAL: cURL option contract broken: {$e->getMessage()}\n");
    fwrite(STDERR, "[curl-contract] every outbound HTTP request would fail at handle teardown.\n");
    fwrite(STDERR, "[curl-contract] check the Swoole build and the Guzzle guard above.\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[curl-contract] FATAL: unexpected %s: %s\n",
        get_class($e),
        $e->getMessage()
    ));
    exit(1);
}

fwrite(STDERR, "[curl-contract] FATAL: expected a ConnectException from 127.0.0.1:1\n");
exit(1);
