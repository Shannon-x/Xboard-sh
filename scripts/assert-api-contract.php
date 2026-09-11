<?php

// No PHPUnit dependency: execute against the production PHP 8.2 / --no-dev image.
// Refuse configured installations and force a disposable in-memory database.
$root = dirname(__DIR__);
if (is_file($root . '/.env') || is_file($root . '/bootstrap/cache/config.php')) {
    fwrite(STDERR, "Run only in an unconfigured test checkout/image, without .env or cached config.\n");
    exit(1);
}
foreach ([
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:' . base64_encode(str_repeat('x', 32)),
    'APP_CONFIG_CACHE' => $root . '/bootstrap/cache/compatibility-unused.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$console = $app->make(Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();
$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$check(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:', 'Unsafe test database');
$check($console->call('migrate', ['--force' => true]) === 0, 'Migrations failed: ' . $console->output());
$call = static function (string $method, string $path, array $body = [], ?string $token = null) use ($app, $check): mixed {
    $app['auth']->forgetGuards();
    $request = Illuminate\Http\Request::create($path, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $token ? 'Bearer ' . $token : '',
        'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($body, JSON_THROW_ON_ERROR));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    $check($response->getStatusCode() === 200 && ($payload->status ?? null) === 'success', $path . ': ' . $response->getContent());
    $kernel->terminate($request, $response);
    return $payload->data;
};
$call('GET', '/api/v1/guest/comm/config');
$base = ['group_id' => 1, 'show' => true, 'sell' => true, 'renew' => true,
    'transfer_enable' => 100, 'device_limit' => null, 'speed_limit' => null,
    'reset_traffic_method' => 1, 'prices' => ['monthly' => 3.99]];
App\Models\Plan::create($base + ['name' => 'Sold out', 'sort' => 1, 'capacity_limit' => 0]);
$plan = App\Models\Plan::create($base + ['name' => 'Available', 'sort' => 2, 'capacity_limit' => null]);
$plans = $call('GET', '/api/v1/guest/plan/fetch');
$check(is_array($plans) && count($plans) === 1 && $plans[0]->id === $plan->id, 'Plan list must be a dense JSON array');
$check((float) $plans[0]->month_price === 399.0 && $plans[0]->device_limit === null, 'Legacy price/unlimited contract changed');
$user = App\Models\User::create(['email' => 'contract@example.test',
    'password' => password_hash('compatibility-test-password', PASSWORD_DEFAULT),
    'uuid' => (string) Illuminate\Support\Str::uuid(), 'token' => Illuminate\Support\Str::random(32),
    'balance' => 0, 'transfer_enable' => 0, 'expired_at' => 0]);
$auth = $call('POST', '/api/v1/passport/auth/login', ['email' => $user->email, 'password' => 'compatibility-test-password']);
$token = preg_replace('/^Bearer\s+/i', '', $auth->auth_data);
$check($call('GET', '/api/v1/user/checkLogin', [], $token)->is_login === true, 'Existing auth contract');
$call('GET', '/api/v1/user/info', [], $token);
$call('GET', '/api/v1/user/getSubscribe', [], $token);
$trade = $call('POST', '/api/v1/user/order/save', ['plan_id' => $plan->id,
    'period' => 'month_price', 'options' => null, 'expected_amount' => null], $token);
$order = $call('GET', '/api/v1/user/order/detail?trade_no=' . $trade, [], $token);
$check($order->total_amount === 399 && $order->period === 'month_price', 'Legacy order contract changed');
$call('POST', '/api/v1/user/order/cancel', ['trade_no' => $trade], $token);
$index = file_get_contents($root . '/public/assets/admin/index.html');
preg_match_all('~(?:src|href)="(/assets/admin/[^"?#]+)"~', $index, $matches);
$check(count($matches[1]) > 0, 'Admin entrypoint has no local assets');
foreach ($matches[1] as $asset) $check(is_file($root . '/public' . $asset), 'Missing admin asset: ' . $asset);
echo 'PASS: migrations, dense plans, login/session, bootstrap, legacy order/cancel, admin assets on PHP ' . PHP_VERSION . "\n";
} catch (Throwable $error) {
    // Laravel's console exception renderer must not turn a smoke-test failure into exit 0.
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
