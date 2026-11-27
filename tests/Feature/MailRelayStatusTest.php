<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\User;
use App\Services\Mail\MailRelay;
use App\Services\Mail\MailRelayStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** 后台仪表盘「邮件投递」卡片的接口：数字来自投递服务的心跳和 v2_mail_log */
class MailRelayStatusTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = ['sent' => 0, 'failed' => 0, 'by_category' => []];

    private function heartbeat(int $now, array $extra = []): array
    {
        return $extra + [
            'at' => $now - 3, 'started_at' => $now - 3600, 'smtp' => true, 'version' => '0.1.0', 'inflight' => 2,
            'bulk_factor' => 1.0, 'daily_used' => 1200, 'daily_limit' => 45000, 'daily_bulk_cap' => 40000,
            'rate_per_minute' => 18000, 'connections' => 16,
            'per_minute' => [0, 5, 7, 3],
            'depths' => ['instant' => 0, 'transactional' => 1, 'bulk' => 340], 'delayed' => 4,
            'counters' => ['sent' => ['instant' => 10, 'transactional' => 4, 'bulk' => 900], 'failed' => ['permanent' => 2, 'throttled' => 1],
                'throttle_events' => 3, 'retried' => 5, 'deferred' => 0, 'expired' => 1],
        ];
    }

    public function test_online_relay_reports_lanes_backlog_rate_and_quota(): void
    {
        $now = 1_800_000_000;
        $s = MailRelayStatus::build($this->heartbeat($now), ['instant', 'transactional', 'bulk'], $now, self::LOG);

        $this->assertTrue($s['online']);
        $this->assertSame(3, $s['heartbeat_age']);
        $this->assertSame(['relay', 'relay', 'relay'], array_column($s['lanes'], 'mode'));
        $this->assertSame([0, 1, 340], array_column($s['lanes'], 'pending'));
        $this->assertSame(7, $s['rate']['last_minute'], '最后一格是没走完的这一分钟，取上一整分钟');
        $this->assertSame(15, $s['rate']['last_hour']);
        $this->assertSame(18000, $s['rate']['limit']);
        $this->assertFalse($s['daily']['bulk_paused']);
        $this->assertSame(3, $s['since_start']['failed']);
        $this->assertSame(3, $s['since_start']['throttled']);
    }

    public function test_bulk_pauses_when_its_share_of_the_daily_quota_is_used(): void
    {
        $now = 1_800_000_000;
        $s = MailRelayStatus::build($this->heartbeat($now, ['daily_used' => 40000]), ['bulk'], $now, self::LOG);
        $this->assertTrue($s['daily']['bulk_paused']);
        $this->assertFalse($s['daily']['full']);
        $this->assertSame(['direct', 'direct', 'relay'], array_column($s['lanes'], 'mode'));
    }

    public function test_stale_heartbeat_means_the_panel_is_sending_itself(): void
    {
        $now = 1_800_000_000;
        $s = MailRelayStatus::build($this->heartbeat($now, ['at' => $now - 90]), ['instant', 'bulk'], $now, self::LOG);
        $this->assertFalse($s['online']);
        $this->assertSame(['fallback', 'direct', 'fallback'], array_column($s['lanes'], 'mode'));
        $this->assertSame([null, null, null], array_column($s['lanes'], 'pending'), '离线时的积压数是旧的，不显示');
        $this->assertFalse($s['daily']['bulk_paused']);

        $none = MailRelayStatus::build(null, [], $now, self::LOG);
        $this->assertFalse($none['online']);
        $this->assertFalse($none['any_enabled']);
        $this->assertNull($none['heartbeat_age']);
        $this->assertSame([], $none['rate']['per_minute']);
    }

    public function test_admin_endpoint(): void
    {
        $hb = $this->heartbeat(time());
        Redis::shouldReceive('get')->with(MailRelay::key('heartbeat'))->andReturn(json_encode($hb));
        config(['mail_lanes.relay.lanes' => 'instant,transactional,bulk']);
        MailLog::create(['email' => 'a@example.test', 'subject' => 's', 'template_name' => 'notify', 'status' => 1, 'error' => null]);
        MailLog::create(['email' => 'b@example.test', 'subject' => 's', 'template_name' => 'notify', 'status' => 0, 'error' => 'x', 'category' => 'bounce']);

        $path = '/api/v2/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))) . '/system/getMailRelayStats';
        $this->getJson($path)->assertStatus(403);

        Sanctum::actingAs(User::create(['email' => Str::random(8) . '@example.test', 'password' => 'x', 'uuid' => (string) Str::uuid(), 'token' => Str::random(32), 'is_admin' => 1]), ['admin']);
        $data = $this->getJson($path)->assertStatus(200)->json('data');
        $this->assertTrue($data['online']);
        $this->assertSame(340, $data['lanes'][2]['pending']);
        $this->assertSame(['sent' => 1, 'failed' => 1, 'by_category' => ['bounce' => 1]], $data['last_24h']);
    }
}
