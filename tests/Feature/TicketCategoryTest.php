<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\TicketController as AdminTicketController;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketCategory\TicketCategories;
use App\Services\TicketService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 工单分类 + 建议 / 反馈：建单落分类、求助与反馈分开限流、后台改分类与跟进状态、统计。
 *
 * Setting 用内存桩替换（同 TicketAttachmentTest）。
 */
class TicketCategoryTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $stub = new class extends Setting {
            public array $store = [];

            public function __construct()
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[strtolower($key)] ?? $default;
            }

            public function save(array $settings): bool
            {
                foreach ($settings as $k => $v) {
                    $this->store[strtolower($k)] = $v;
                }
                return true;
            }

            public function set(string $key, mixed $value = null): bool
            {
                return $this->save([$key => $value]);
            }
        };
        $stub->store = &$this->settings;
        $this->app->instance(Setting::class, $stub);
        $this->settings = [];
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'cat-' . Str::random(6) . '@example.com',
            'password' => 'x',
            'uuid' => Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
            'plan_id' => null,
            'group_id' => null,
        ]);
    }

    public function test_legacy_frontend_without_category_lands_in_other(): void
    {
        Sanctum::actingAs($user = $this->makeUser());

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => '连不上',
            'level' => 1,
            'message' => '所有节点都超时',
        ])->assertStatus(200);

        $ticket = Ticket::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('other', $ticket->category);
        $this->assertSame(TicketCategories::TYPE_SUPPORT, $ticket->type);
        $this->assertNull($ticket->feedback_state);
    }

    public function test_feedback_ticket_gets_received_state_and_lowest_level(): void
    {
        Sanctum::actingAs($user = $this->makeUser());

        $this->postJson('/api/v1/user/ticket/save', [
            'subject' => '希望支持按地区筛选节点',
            'level' => 2,
            'category' => 'suggestion',
            'message' => '节点太多了，找香港的要翻很久',
        ])->assertStatus(200);

        $ticket = Ticket::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(TicketCategories::TYPE_FEEDBACK, $ticket->type);
        $this->assertSame(TicketCategories::FEEDBACK_RECEIVED, $ticket->feedback_state);
        $this->assertSame(0, (int) $ticket->level, '建议不分紧急程度');

        $this->getJson('/api/v1/user/ticket/fetch')
            ->assertStatus(200)
            ->assertJsonPath('data.0.category', 'suggestion')
            ->assertJsonPath('data.0.type', 1)
            ->assertJsonPath('data.0.feedback_state', 'received');
    }

    public function test_open_support_ticket_does_not_block_feedback_and_vice_versa(): void
    {
        $user = $this->makeUser();
        $service = new TicketService();

        $service->createTicket($user->id, '订阅更新失败', 1, '一直 403', [], 'subscription');
        // 手上有一张没关的求助单，照样能提建议
        $service->createTicket($user->id, '建议加深色模式', 0, '晚上太亮', [], 'suggestion');
        $service->createTicket($user->id, '结账页太长', 0, '要滑很久', [], 'experience');

        // 求助单仍然同时只能开一张
        try {
            $service->createTicket($user->id, '又一个问题', 1, '...', [], 'connection');
            $this->fail('第二张求助单应被拒绝');
        } catch (ApiException) {
        }

        // 建议与反馈上限 MAX_OPEN_FEEDBACK 张
        $service->createTicket($user->id, '建议三', 0, '...', [], 'suggestion');
        $this->expectException(ApiException::class);
        $service->createTicket($user->id, '建议四', 0, '...', [], 'suggestion');
    }

    public function test_hidden_and_system_categories_are_not_selectable(): void
    {
        $this->settings['ticket_category_hidden'] = 'billing, other';
        $codes = TicketCategories::selectableCodes();

        $this->assertNotContains('billing', $codes);
        $this->assertNotContains('withdraw', $codes, '提现是系统建单专用');
        $this->assertContains('other', $codes, 'other 是兜底，隐藏不掉');

        $user = $this->makeUser();
        $this->expectException(ApiException::class);
        (new TicketService())->createTicket($user->id, '充值', 1, '...', [], 'billing');
    }

    public function test_feedback_switch_removes_feedback_categories(): void
    {
        $this->settings['ticket_feedback_enable'] = 0;
        Sanctum::actingAs($this->makeUser());

        $categories = collect($this->getJson('/api/v1/user/comm/config')->assertStatus(200)->json('data.ticket_categories'));
        $this->assertFalse($categories->contains('type', TicketCategories::TYPE_FEEDBACK));
        $this->assertTrue($categories->contains('code', 'connection'));
    }

    public function test_admin_recategorize_moves_type_and_feedback_state(): void
    {
        $user = $this->makeUser();
        $service = new TicketService();
        $ticket = $service->createTicket($user->id, '能不能加个流量预警', 1, '...', [], 'other');

        // 误分进「其他问题」的建议挪到「功能建议」：开始有跟进状态
        $ticket = $service->updateByAdmin($ticket, ['category' => 'suggestion']);
        $this->assertSame(TicketCategories::TYPE_FEEDBACK, $ticket->fresh()->type);
        $this->assertSame('received', $ticket->fresh()->feedback_state);

        $service->updateByAdmin($ticket, ['feedback_state' => 'planned']);
        $this->assertSame('planned', $ticket->fresh()->feedback_state);

        // 挪回求助类：跟进状态清空
        $service->updateByAdmin($ticket->fresh(), ['category' => 'account']);
        $this->assertSame(TicketCategories::TYPE_SUPPORT, $ticket->fresh()->type);
        $this->assertNull($ticket->fresh()->feedback_state);

        $this->expectException(ApiException::class);
        $service->updateByAdmin($ticket->fresh(), ['feedback_state' => 'shipped']);
    }

    public function test_admin_stat_counts_open_by_category_and_feedback_by_state(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $service = new TicketService();
        $service->createTicket($a->id, '节点超时', 1, '...', [], 'connection');
        $service->createTicket($b->id, '节点超时', 1, '...', [], 'connection');
        $s = $service->createTicket($a->id, '建议', 0, '...', [], 'suggestion');
        $service->updateByAdmin($s, ['feedback_state' => 'shipped']);

        $data = app(AdminTicketController::class)->stat()->getData(true)['data'];

        $this->assertSame(2, $data['counts']['by_category']['connection']['open']);
        $this->assertSame(2, $data['counts']['by_category']['connection']['pending']);
        $this->assertSame(1, $data['counts']['feedback_by_state']['shipped']);
        $this->assertSame(3, $data['counts']['pending_reply']);
        $this->assertContains('withdraw', array_column($data['categories'], 'code'));

        $list = app(AdminTicketController::class)->fetch(new Request(['type' => 1]))->getOriginalContent();
        $this->assertSame(1, $list['total']);
    }
}
