<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Services\KnowledgePublicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function __construct(private KnowledgePublicationService $publication) {}

    public function fetch(Request $request)
    {
        if (!config('knowledge.public_enabled')) {
            return $this->fail([503, 'Public knowledge is not enabled.'], null, ['code' => 'knowledge_public_disabled']);
        }
        $input = $request->validate([
            'language' => ['sometimes', 'required', 'string', Rule::in(Knowledge::PUBLIC_LANGUAGES)],
            'category' => 'nullable|string|max:128',
            'keyword' => 'nullable|string|max:120',
            'page' => 'sometimes|integer|min:1|max:100000',
            'page_size' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = $this->publication->publicQuery($input['language'] ?? 'zh-CN');
        $currentPage = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['page_size'] ?? 12);
        $offset = ($currentPage - 1) * $perPage;
        $keyword = trim($input['keyword'] ?? '');
        $items = [];
        $categories = [];
        $total = 0;
        // Stream small batches, discard bodies immediately, and paginate only
        // rows satisfying the SAME final publication guard as article(). This
        // also fails closed for malformed imports or manual database edits.
        foreach ($query->orderBy('sort')->orderBy('id')->lazy(50) as $article) {
            if (!$this->publication->isSafePublicArticle($article)) {
                continue;
            }
            $metadata = $this->publication->publicMetadata($article);
            $category = $metadata['category'];
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            if (!empty($input['category']) && $category !== $input['category']) {
                continue;
            }
            if ($keyword !== '' && mb_stripos($metadata['title'] . ' ' . $metadata['summary'] . ' ' .
                strip_tags($this->publication->publicText($article->body)), $keyword) === false) {
                continue;
            }
            if ($total >= $offset && count($items) < $perPage) {
                $items[] = $metadata;
            }
            $total++;
        }
        ksort($categories);
        return $this->success([
            'items' => $items,
            'categories' => collect($categories)->map(fn ($count, $name) => ['name' => (string) $name, 'count' => $count])->values(),
            'pagination' => ['current_page' => $currentPage, 'per_page' => $perPage,
                'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    public function article(Request $request, string $slug)
    {
        if (!config('knowledge.public_enabled')) {
            return $this->fail([503, 'Public knowledge is not enabled.'], null, ['code' => 'knowledge_public_disabled']);
        }
        $language = $request->query('language', 'zh-CN');
        abort_unless(is_string($language) && in_array($language, Knowledge::PUBLIC_LANGUAGES, true), 404);
        $article = $this->publication->publicQuery($language)->where('slug', $slug)->first();
        abort_unless($article, 404);
        abort_unless($this->publication->isSafePublicArticle($article), 404);
        return $this->success($this->publication->publicMetadata($article) + [
            'body' => $this->publication->renderPublicBody($article->body),
            'body_format' => 'html',
        ]);
    }
}
