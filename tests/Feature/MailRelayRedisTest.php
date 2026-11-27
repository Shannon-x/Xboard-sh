<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Services\Mail\MailLane;
use App\Services\Mail\MailRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 端到端：面板入队 → 真的 Redis → 真的 Rust 投递服务 → 一个假 SMTP → 结果流 → mail:relay-results。
 * 默认跳过；手动跑法见 docs/mail-relay.md「本地联调」（需要本机 redis-server、投递服务和 SMTP 测试桩都在跑）：
 *   MAIL_RELAY_E2E=1 REDIS_PREFIX=e2e_ php vendor/bin/phpunit tests/Feature/MailRelayRedisTest.php
 */
class MailRelayRedisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('MAIL_RELAY_E2E')) {
            $this->markTestSkipped('端到端联调，设 MAIL_RELAY_E2E=1 才跑');
        }
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::forgetDriver('redis');
        MailRelay::$sink = null;
        MailRelay::reset();
    }

    public function test_round_trip_through_the_rust_relay(): void
    {
        admin_setting(['email_host' => getenv('MAIL_RELAY_E2E_SMTP_HOST') ?: '127.0.0.1', 'email_port' => getenv('MAIL_RELAY_E2E_SMTP_PORT') ?: 2525, 'email_encryption' => '', 'email_username' => '', 'email_password' => '']);
        config(['mail_lanes.relay.lanes' => 'bulk', 'mail_lanes.relay.inline_max_bytes' => 512]);
        MailRelay::syncConfig(true);
        // 投递服务每 10 秒看一次配置；心跳要等它换上这份配置
        $deadline = time() + 25;
        $version = Redis::hget(MailRelay::key('config'), 'version');
        while (time() < $deadline) {
            $hb = json_decode((string) Redis::get(MailRelay::key('heartbeat')), true);
            if (($hb['smtp_version'] ?? null) === $version) {
                break;
            }
            usleep(500_000);
        }
        $this->assertTrue(MailRelay::alive(), '投递服务没在跑，或没拿到 SMTP 配置');

        $email = 'e2e-' . uniqid() . '@example.test';
        (new SendEmailJob(['email' => $email, 'subject' => '联调', 'template_name' => 'notify', 'template_value' => ['name' => 'X', 'url' => 'https://x.test', 'content' => str_repeat('长正文 ', 200)]], MailLane::BULK))->handle();
        $this->assertSame(0, MailLog::where('email', $email)->count(), '入队时不记日志');

        $deadline = time() + 20;
        while (time() < $deadline && MailLog::where('email', $email)->count() === 0) {
            Artisan::call('mail:relay-results', ['--seconds' => 2]);
        }
        $log = MailLog::where('email', $email)->first();
        $this->assertNotNull($log, '结果没回来');
        $this->assertSame(1, (int) $log->status, (string) $log->error);
        $this->assertSame([], glob(MailRelay::spoolDir() . '/*.eml') ?: [], 'spool 文件在结果回来后删掉');
    }
}
