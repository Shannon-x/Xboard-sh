<?php

namespace App\Services;

use App\Models\Knowledge;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class KnowledgePublicationService
{
    public function publicQuery(string $language): Builder
    {
        return Knowledge::query()->where('visibility', 'public')->where('show', true)
            ->where('language', $language)->whereNotNull('published_at')
            ->whereNotNull('slug')->where('slug', '!=', '');
    }

    public function userQuery(User $user): Builder
    {
        $allowed = ['public', 'members'];
        if (app(UserService::class)->isAvailable($user)) {
            $allowed[] = 'subscribers';
        }
        // Even an administrator uses the admin endpoint to read internal work.
        // Unknown / NULL values are never accepted by a negative exclusion rule.
        return Knowledge::query()->where('show', true)->whereIn('visibility', $allowed);
    }

    public function validateForSave(Knowledge $article, bool $reviewed): void
    {
        $rules = [
            'visibility' => ['required', Rule::in(Knowledge::VISIBILITIES)],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('v2_knowledge', 'slug')->where('language', $article->language)->ignore($article->id)],
            'summary' => ['nullable', 'string', 'max:500'],
        ];
        if ($article->visibility === 'public') {
            $rules['language'] = ['required', Rule::in(Knowledge::PUBLIC_LANGUAGES)];
            $rules['slug'][0] = 'required';
            $rules['summary'][0] = 'required';
        }
        if ($article->visibility === 'public' && $article->show) {
            if (!$reviewed) {
                throw ValidationException::withMessages([
                    'public_reviewed' => ['公开发布或编辑公开文章前，请确认已检查正文、摘要及图片，不含个人凭证或内部内容。'],
                ]);
            }
        }
        Validator::make($article->getAttributes(), $rules)->validate();

        if ($article->visibility === 'public') {
            foreach (['title', 'summary', 'category', 'body'] as $field) {
                if ($this->hasPrivateMarkers((string) $article->{$field})) {
                    throw ValidationException::withMessages([
                        $field => ['公开指南不能包含订阅凭证、个人占位符或 access 保护区。请改写为通用说明。'],
                    ]);
                }
            }
        }
        if ($article->visibility === 'public' && $article->show && !$article->published_at) {
            $article->published_at = time();
        }
    }

    public function hasPrivateMarkers(string $source): bool
    {
        // Decode entity/URL encodings before checking pasted templates or URLs.
        // This is a publishing guard, not a guarantee of automated secret detection.
        $decoded = $source;
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode(rawurldecode($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // One exact, case-sensitive public template is allowed. Unknown,
        // malformed and future templates fail closed instead of guessing safe.
        $decoded = str_replace('{{siteName}}', '', $decoded);
        return str_contains($decoded, '{{') || str_contains($decoded, '}}') ||
            (bool) preg_match('/<!--\s*access\b|\/api\/v[12]\/client\/subscribe\b|[?&](?:token|auth_data|access_token)=/iu', $decoded);
    }

    public function publicMetadata(Knowledge $article): array
    {
        return [
            'slug' => $article->slug,
            'language' => $article->language,
            'category' => $this->publicText((string) $article->category),
            'title' => $this->publicText((string) $article->title),
            'summary' => $this->publicText((string) $article->summary),
            'published_at' => $article->published_at,
            'updated_at' => $article->updated_at,
        ];
    }

    public function isSafePublicArticle(Knowledge $article): bool
    {
        if (!is_string($article->slug) || strlen($article->slug) > 120 ||
            !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $article->slug) ||
            !is_string($article->summary) || trim($article->summary) === '' || mb_strlen($article->summary) > 500) {
            return false;
        }
        foreach (['title', 'summary', 'category', 'body'] as $field) {
            if ($this->hasPrivateMarkers((string) $article->{$field})) {
                return false;
            }
        }
        return true;
    }

    public function renderPublicBody(string $source): string
    {
        $source = str_replace('{{siteName}}', htmlspecialchars((string) admin_setting('app_name', 'XBoard'),
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $source);
        // CommonMark handles mixed Markdown and HTML; HTML is allowed only as
        // intermediate input. Never return it before the final allowlist pass.
        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'allow', 'allow_unsafe_links' => false,
            // Let the final sanitizer drop unsafe nodes and their contents,
            // rather than GFM escaping script text into visible prose first.
            'disallowed_raw_html' => ['disallowed_tags' => []],
            'max_nesting_level' => 20, 'max_delimiters_per_line' => 1000,
        ]))->convert($source)->getContent();
        $config = (new HtmlSanitizerConfig())
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks()->allowMediaSchemes(['https', 'http'])
            ->allowRelativeMedias()->withMaxInputLength(2_000_000)
            ->withAttributeSanitizer(new KnowledgeUrlSanitizer(config('knowledge.media_base_url')));
        foreach (['p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'code', 'pre',
            'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
            'span', 'div', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('a', ['href', 'title'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowElement('img', ['src', 'alt', 'title'])
            ->forceAttribute('img', 'loading', 'lazy')
            ->forceAttribute('img', 'referrerpolicy', 'no-referrer')
            ->allowElement('td', ['colspan', 'rowspan'])->allowElement('th', ['colspan', 'rowspan']);
        return (new HtmlSanitizer($config))->sanitize($html);
    }

    public function publicText(string $source): string
    {
        return str_replace('{{siteName}}', (string) admin_setting('app_name', 'XBoard'), $source);
    }
}
