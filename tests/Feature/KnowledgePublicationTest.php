<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\User;
use App\Services\KnowledgePublicationService;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnowledgePublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knowledge.public_enabled' => true]);
    }

    private function signIn(string $role): User
    {
        $user = User::create([
            'email' => Str::random(12) . '@example.test', 'password' => 'test',
            'uuid' => (string) Str::uuid(), 'token' => 'private-reader-token-' . Str::random(12),
            'balance' => 0, 'transfer_enable' => $role === 'member' ? 0 : 100,
            'expired_at' => $role === 'expired' ? time() - 3600 : time() + 86400,
            'banned' => $role === 'banned', 'is_admin' => $role === 'admin',
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function article(string $visibility = 'public', array $extra = []): Knowledge
    {
        return Knowledge::create(array_merge([
            'category' => $visibility, 'language' => 'zh-CN', 'title' => $visibility . ' article',
            'body' => 'Readable body for ' . $visibility, 'summary' => 'A reviewed public summary',
            'visibility' => $visibility, 'show' => true, 'sort' => 1,
            'slug' => Str::lower(Str::random(12)),
            'published_at' => $visibility === 'public' ? time() - 60 : null,
        ], $extra));
    }

    private function adminPath(string $action): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $prefix . '/knowledge/' . $action;
    }

    private function form(array $extra = []): array
    {
        return array_merge(['title' => 'Getting started', 'category' => 'Start', 'language' => 'zh-CN',
            'body' => "## Connect\n\nA safe guide.", 'visibility' => 'public', 'show' => true,
            'slug' => 'getting-started', 'summary' => 'Three steps to start.', 'public_reviewed' => true], $extra);
    }

    public static function readers(): array
    {
        return ['member' => ['member', false], 'subscriber' => ['subscriber', true],
            'expired' => ['expired', false], 'banned' => ['banned', false], 'admin' => ['admin', true]];
    }

    #[DataProvider('readers')]
    public function test_user_list_detail_category_and_search_share_permissions(string $role, bool $subscribed): void
    {
        $this->signIn($role);
        $articles = [];
        foreach (Knowledge::VISIBILITIES as $visibility) {
            $articles[$visibility] = $this->article($visibility, ['title' => 'needle-' . $visibility]);
        }
        $hidden = $this->article('members', ['show' => false, 'category' => 'hidden']);
        $unknown = $this->article('future-scope', ['category' => 'unknown']);
        $allowed = $subscribed ? ['public', 'members', 'subscribers'] : ['public', 'members'];
        $response = $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN')->assertOk();
        $this->assertEqualsCanonicalizing($allowed, array_keys($response->json('data')));
        foreach ($articles as $visibility => $article) {
            $expected = in_array($visibility, $allowed, true);
            $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)
                ->assertStatus($expected ? 200 : 404);
            $search = $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN&keyword=needle-' . $visibility)->assertOk();
            $this->assertCount($expected ? 1 : 0, $search->json('data'));
        }
        foreach ([$hidden, $unknown] as $article) {
            $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)->assertNotFound();
        }
        $categories = $this->getJson('/api/v1/user/knowledge/getCategory?language=zh-CN')->assertOk();
        $this->assertEqualsCanonicalizing($allowed, $categories->json('data'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_guest_endpoints_never_disclose_private_metadata_or_hooks(): void
    {
        $public = $this->article('public', ['slug' => 'public-guide']);
        $private = [];
        foreach (['members', 'subscribers', 'admin', 'invalid'] as $visibility) {
            $private[] = $this->article($visibility, ['slug' => $visibility . '-guide']);
        }
        $private[] = $this->article('public', ['show' => false, 'slug' => 'draft-guide', 'category' => 'draft', 'title' => 'draft-only-title']);
        $private[] = $this->article('public', ['published_at' => null, 'slug' => 'unreviewed-guide', 'category' => 'unreviewed', 'title' => 'unreviewed-only-title']);
        HookManager::registerFilter('user.knowledge.resource', fn ($data) => $data + ['private_hook' => 'secret']);
        $response = $this->getJson('/api/v1/guest/knowledge/fetch?language=zh-CN')->assertOk();
        $response->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.categories.0.name', 'public');
        $this->assertCount(1, $response->json('data.categories'));
        $this->assertSame(['slug', 'language', 'category', 'title', 'summary', 'published_at', 'updated_at'],
            array_keys($response->json('data.items.0')));
        foreach ($private as $article) {
            $this->getJson('/api/v1/guest/knowledge/article/' . $article->slug . '?language=zh-CN')->assertNotFound();
            $this->getJson('/api/v1/guest/knowledge/fetch?keyword=' . urlencode($article->title))
                ->assertOk()->assertJsonPath('data.pagination.total', 0);
        }
        $detail = $this->getJson('/api/v1/guest/knowledge/article/' . $public->slug)->assertOk();
        $detail->assertJsonPath('data.body_format', 'html');
        $this->assertStringNotContainsString('private_hook', $detail->getContent());
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
        $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN')->assertForbidden();
        $this->getJson($this->adminPath('capabilities'))->assertForbidden();
        $this->signIn('member');
        $this->getJson($this->adminPath('capabilities'))->assertForbidden();
        $this->postJson($this->adminPath('save'), $this->form())->assertForbidden();
    }

    public function test_public_page_shape_language_pagination_and_closed_switch(): void
    {
        $this->article('public', ['slug' => 'same-slug', 'language' => 'zh-CN', 'sort' => 1]);
        $this->article('public', ['slug' => 'second', 'language' => 'zh-CN', 'sort' => 2]);
        $this->article('public', ['slug' => 'same-slug', 'language' => 'en']);
        $this->getJson('/api/v1/guest/knowledge/fetch?language=zh-CN&page_size=1&page=2')->assertOk()
            ->assertJsonPath('data.items.0.slug', 'second')->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.current_page', 2)->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2)->assertJsonPath('data.categories.0.count', 2);
        $this->getJson('/api/v1/guest/knowledge/article/same-slug?language=en')->assertOk()
            ->assertJsonPath('data.language', 'en');
        $this->getJson('/api/v1/guest/knowledge/article/same-slug?language=ja')->assertNotFound();
        $this->getJson('/api/v1/guest/knowledge/article/same-slug?language=garbage')->assertNotFound();
        $this->getJson('/api/v1/guest/knowledge/fetch?page_size=1000')->assertUnprocessable();
        $this->getJson('/api/v1/guest/knowledge/fetch?page=0')->assertUnprocessable();
        $this->getJson('/api/v1/guest/knowledge/fetch?category=absent')->assertOk()->assertJsonPath('data.items', []);
        config(['knowledge.public_enabled' => false]);
        $response = $this->getJson('/api/v1/guest/knowledge/article/same-slug')->assertStatus(503)
            ->assertJsonPath('error.code', 'knowledge_public_disabled');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/guest/knowledge/fetch')->assertStatus(503);
    }

    public function test_publication_review_slug_uniqueness_and_retraction(): void
    {
        $this->signIn('admin');
        $this->getJson($this->adminPath('capabilities'))->assertOk()
            ->assertJsonPath('data.publication_version', 1)->assertJsonPath('data.visibilities', Knowledge::VISIBILITIES);
        $this->postJson($this->adminPath('save'), $this->form(['public_reviewed' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('public_reviewed');
        $this->postJson($this->adminPath('save'), $this->form(['summary' => '']))->assertUnprocessable();
        $this->postJson($this->adminPath('save'), $this->form(['slug' => 'Invalid Slug']))->assertUnprocessable();
        $this->postJson($this->adminPath('save'), $this->form())->assertOk()->assertJsonPath('data', true);
        $article = Knowledge::where('slug', 'getting-started')->firstOrFail();
        $published = $article->published_at;
        $this->assertNotNull($published);
        $this->postJson($this->adminPath('save'), $this->form())->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->adminPath('save'), $this->form(['language' => 'en']))->assertOk();
        $legacy = $this->form(['id' => $article->id, 'title' => 'Edited title']);
        unset($legacy['visibility'], $legacy['public_reviewed']);
        $this->postJson($this->adminPath('save'), $legacy)->assertUnprocessable()->assertJsonValidationErrors('public_reviewed');
        $this->assertSame('Getting started', $article->fresh()->title);
        $this->postJson($this->adminPath('save'), $this->form(['id' => $article->id, 'body' => 'Updated safe text']))->assertOk();
        $this->assertSame($published, $article->fresh()->published_at);
        $this->getJson('/api/v1/guest/knowledge/article/getting-started')->assertOk();
        $this->postJson($this->adminPath('show'), ['id' => $article->id])->assertOk();
        $this->getJson('/api/v1/guest/knowledge/article/getting-started')->assertNotFound();
        $this->postJson($this->adminPath('show'), ['id' => $article->id])->assertUnprocessable();
        $this->postJson($this->adminPath('save'), $this->form(['id' => $article->id]))->assertOk();
        $this->postJson($this->adminPath('save'), $this->form(['id' => $article->id, 'visibility' => 'admin', 'public_reviewed' => false]))->assertOk();
        $this->getJson('/api/v1/guest/knowledge/article/getting-started')->assertNotFound();
        $this->getJson('/api/v1/guest/knowledge/fetch?language=zh-CN')->assertOk()->assertJsonPath('data.items', []);
        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)->assertNotFound();
        $this->getJson($this->adminPath('fetch') . '?id=' . $article->id)->assertOk()->assertJsonPath('data.visibility', 'admin');
        $this->artisan('xboard:check-upgrade --require-legacy-rollback')->assertFailed();
    }

    public static function privateSources(): array
    {
        return [
            'subscription' => ['{{subscribeUrl}}'], 'encoded subscription' => ['{{urlEncodeSubscribeUrl}}'],
            'base64 subscription' => ['{{safeBase64SubscribeUrl}}'],
            'protected block' => ['<!--access start-->private<!--access end-->'],
            'unclosed block' => ['<!--access start-->private'],
            'entity template' => ['&#123;&#123;subscribeUrl&#125;&#125;'],
            'copied subscription' => ['https://example.test/api/v1/client/subscribe?token=private'],
            'query token' => ['https://example.test/import?access_token=private'],
            'unknown future template' => ['{{userToken}}'], 'malformed template' => ['{{broken'],
            'site name wrong case' => ['{{sitename}}'], 'unknown access block' => ['<!--access secret-->'],
        ];
    }

    #[DataProvider('privateSources')]
    public function test_public_save_rejects_private_markers_in_body_or_metadata(string $private): void
    {
        $this->signIn('admin');
        foreach (['body', 'title', 'summary', 'category'] as $field) {
            $this->postJson($this->adminPath('save'), $this->form([$field => $private]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, Knowledge::count());
    }

    public function test_legacy_member_access_blocks_and_personalization_are_preserved_but_not_searchable(): void
    {
        $user = $this->signIn('member');
        $article = $this->article('members', ['body' => 'Hello {{siteName}} <!--access start-->private-needle<!--access end--> {{subscribeUrl}}']);
        $response = $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)->assertOk();
        $this->assertStringNotContainsString('private-needle', $response->json('data.body'));
        $this->assertStringContainsString($user->token, $response->json('data.body'));
        $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN&keyword=private-needle')->assertOk()->assertJsonPath('data', []);
        $user->transfer_enable = 100;
        $user->save();
        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)->assertOk()->assertSee('private-needle');
        $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN&keyword=private-needle')->assertOk()
            ->assertJsonPath('data.members.0.id', $article->id);
    }

    public function test_legacy_admin_keeps_member_permissions_and_new_defaults_are_private_drafts(): void
    {
        $this->signIn('admin');
        $legacy = ['title' => 'Old document', 'category' => 'Legacy', 'language' => 'zh-CN', 'body' => 'Old body'];
        $this->postJson($this->adminPath('save'), $legacy)->assertOk();
        $article = Knowledge::firstOrFail();
        $this->assertSame('members', $article->visibility);
        $this->assertFalse($article->show);
        $article->visibility = 'admin';
        $article->save();
        $this->postJson($this->adminPath('save'), $legacy + ['id' => $article->id, 'show' => true])->assertOk();
        $this->assertSame('admin', $article->fresh()->visibility);
        $this->postJson($this->adminPath('save'), $legacy + ['visibility' => 'everyone'])->assertUnprocessable();
        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)->assertNotFound();
    }

    public function test_migration_defaults_existing_articles_without_changing_legacy_body_or_show(): void
    {
        $migration = require database_path('migrations/2026_09_14_000001_add_knowledge_publication.php');
        $migration->down();
        $id = DB::table('v2_knowledge')->insertGetId(['language' => 'zh-CN', 'category' => 'Legacy', 'title' => 'Old',
            'body' => '<!--access start-->Keep secret<!--access end-->', 'show' => true, 'created_at' => time(), 'updated_at' => time()]);
        $migration->up();
        $migration->up();
        $article = Knowledge::findOrFail($id);
        $this->assertSame('members', $article->visibility);
        $this->assertTrue($article->show);
        $this->assertSame('<!--access start-->Keep secret<!--access end-->', $article->body);
        $this->assertNull($article->published_at);
        $this->getJson('/api/v1/guest/knowledge/fetch')->assertOk()->assertJsonPath('data.items', []);
        $article->visibility = 'admin';
        $article->save();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot safely roll back');
        $migration->down();
    }

    public function test_public_html_is_sanitized_on_server_and_markdown_is_rendered_once(): void
    {
        $source = "## Heading\n\n**Strong** [safe](/help)\n\n"
            . '<div class="evil" style="position:fixed" onclick="alert(1)"><p>HTML paragraph</p>'
            . '<script>window.secret=1</script><iframe src="https://evil.test"></iframe>'
            . '<a href="javascript:alert(1)">bad</a><img src="data:image/svg+xml,evil" onerror="alert(1)">'
            . '<img src="https://example.test/paper.png" alt="Paper"><form><input name="password"></form></div>';
        $article = $this->article('public', ['body' => $source]);
        $response = $this->getJson('/api/v1/guest/knowledge/article/' . $article->slug)->assertOk();
        $html = $response->json('data.body');
        foreach (['<h2>Heading</h2>', '<strong>Strong</strong>', 'HTML paragraph', 'https://example.test/paper.png'] as $safe) {
            $this->assertStringContainsString($safe, $html);
        }
        foreach (['<script', 'window.secret', '<iframe', 'javascript:', 'onclick', 'onerror', 'data:image', 'style=', 'class=', '<form', '<input'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->assertStringContainsString('noopener noreferrer', $html);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $html);
    }

    public function test_only_exact_public_site_name_template_is_rendered(): void
    {
        $article = $this->article('public', ['title' => '{{siteName}} guide', 'summary' => 'Welcome to {{siteName}}',
            'body' => '## {{siteName}} guide']);
        $service = app(KnowledgePublicationService::class);
        $this->assertFalse($service->hasPrivateMarkers($article->body));
        $detail = $this->getJson('/api/v1/guest/knowledge/article/' . $article->slug)->assertOk();
        $this->assertStringNotContainsString('{{siteName}}', $detail->getContent());
        $detail->assertJsonPath('data.title', admin_setting('app_name', 'XBoard') . ' guide');
        $this->getJson('/api/v1/guest/knowledge/fetch')->assertOk()
            ->assertJsonPath('data.items.0.summary', 'Welcome to ' . admin_setting('app_name', 'XBoard'));
    }

    public function test_public_drafts_require_complete_public_metadata_and_contract_limits(): void
    {
        $this->signIn('admin');
        $this->postJson($this->adminPath('save'), $this->form(['show' => false, 'slug' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson($this->adminPath('save'), $this->form(['show' => false, 'summary' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('summary');
        $this->postJson($this->adminPath('save'), $this->form(['slug' => str_repeat('a', 121)]))->assertUnprocessable();
        $this->postJson($this->adminPath('save'), $this->form(['summary' => str_repeat('a', 501)]))->assertUnprocessable();
        $this->postJson($this->adminPath('save'), $this->form(['show' => false, 'public_reviewed' => false]))->assertOk();
        $this->assertNull(Knowledge::firstOrFail()->published_at);
    }

    public function test_relative_resources_use_configured_backend_origin_not_request_host(): void
    {
        config(['knowledge.media_base_url' => 'https://backend.example.test']);
        $article = $this->article('public', ['body' => '<p><img src="/storage/paper.png">'
            . '<img src="storage/second.png"><a href="/storage/manual.pdf">Download</a>'
            . '<a href="#next">Next</a><a href="javascript:alert(1)">Unsafe</a></p>']);
        $response = $this->withHeader('Host', 'attacker.example.test')
            ->getJson('/api/v1/guest/knowledge/article/' . $article->slug)->assertOk();
        $html = $response->json('data.body');
        $this->assertStringContainsString('src="https://backend.example.test/storage/paper.png"', $html);
        $this->assertStringContainsString('src="https://backend.example.test/storage/second.png"', $html);
        $this->assertStringContainsString('href="https://backend.example.test/storage/manual.pdf"', $html);
        $this->assertStringContainsString('href="#next"', $html);
        $this->assertStringNotContainsString('attacker', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        config(['knowledge.media_base_url' => null]);
        $html = app(KnowledgePublicationService::class)->renderPublicBody($article->body);
        $this->assertStringNotContainsString('src=', $html);
        $this->assertStringNotContainsString('/storage/manual.pdf', $html);
        $this->assertStringContainsString('href="#next"', $html);
    }

    public function test_unsafe_imports_are_absent_from_every_guest_surface(): void
    {
        $this->article('public', ['slug' => 'safe']);
        foreach (['title', 'summary', 'body', 'category'] as $field) {
            $bad = $this->article('public', ['slug' => 'bad-' . $field, 'category' => 'private-import',
                $field => 'hidden-import-needle {{unknownPrivateTemplate}}']);
            $this->getJson('/api/v1/guest/knowledge/article/' . $bad->slug)->assertNotFound();
        }
        $response = $this->getJson('/api/v1/guest/knowledge/fetch')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->assertCount(1, $response->json('data.categories'));
        $this->getJson('/api/v1/guest/knowledge/fetch?keyword=hidden-import-needle')->assertOk()->assertJsonPath('data.items', []);
        $this->getJson('/api/v1/guest/knowledge/fetch?category=private-import')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_explicit_hide_is_idempotent_but_legacy_toggle_is_compatible(): void
    {
        $this->signIn('admin');
        foreach (['public', 'members', 'subscribers', 'admin'] as $visibility) {
            $article = $this->article($visibility);
            for ($i = 0; $i < 2; $i++) {
                $this->postJson($this->adminPath('show'), ['id' => $article->id, 'show' => false])->assertOk();
                $this->assertFalse($article->fresh()->show);
            }
            $response = $this->postJson($this->adminPath('show'), ['id' => $article->id]);
            if ($visibility === 'public') {
                $response->assertUnprocessable();
            } else {
                $response->assertOk();
                $this->assertTrue($article->fresh()->show);
            }
        }
    }
}
