<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeResource;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Services\KnowledgePublicationService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'nullable|sometimes|integer|min:1',
            'language' => 'nullable|sometimes|string|max:10',
            'keyword' => 'nullable|sometimes|string|max:255',
        ]);

        return $request->input('id')
            ? $this->fetchSingle($request)
            : $this->fetchList($request);
    }

    private function fetchSingle(Request $request)
    {
        $knowledge = $this->buildKnowledgeQuery($request->user())
            ->where('id', $request->input('id'))
            ->first();

        if (!$knowledge) {
            return $this->fail([404, __('Article does not exist')]);
        }

        $knowledge = $knowledge->toArray();
        $knowledge = $this->processKnowledgeContent($knowledge, $request->user());

        return $this->success(KnowledgeResource::make($knowledge));
    }

    private function fetchList(Request $request)
    {
        $builder = $this->buildKnowledgeQuery($request->user(), ['id', 'category', 'title', 'updated_at', 'body'])
            ->where('language', $request->input('language'))
            ->orderBy('sort', 'ASC');

        $keyword = trim((string) $request->input('keyword', ''));
        $knowledges = $builder->get()
            ->map(function ($knowledge) use ($request) {
                $knowledge = $knowledge->toArray();
                return $this->processKnowledgeContent($knowledge, $request->user());
            })
            // Search only the content this reader may see. Matching the raw
            // access block first would disclose private words via search hits.
            ->filter(fn (array $article) => $keyword === '' ||
                mb_stripos($article['title'] . ' ' . strip_tags($article['body']), $keyword) !== false)
            ->map(fn (array $article) => KnowledgeResource::make($article))
            ->groupBy('category');

        return $this->success($knowledges);
    }

    public function getCategory(Request $request)
    {
        $request->validate(['language' => 'nullable|string|max:10']);
        $query = $this->buildKnowledgeQuery($request->user());
        if ($request->filled('language')) {
            $query->where('language', $request->input('language'));
        }
        return $this->success($query->distinct()->orderBy('category')->pluck('category')->values());
    }

    private function buildKnowledgeQuery(User $user, array $select = ['*'])
    {
        return app(KnowledgePublicationService::class)->userQuery($user)->select($select);
    }

    private function processKnowledgeContent(array $knowledge, User $user): array
    {
        if (!isset($knowledge['body'])) {
            return $knowledge;
        }

        if (!$this->userService->isAvailable($user)) {
            $this->formatAccessData($knowledge['body']);
        }
        $subscribeUrl = Helper::getSubscribeUrl($user['token']);
        $knowledge['body'] = $this->replacePlaceholders($knowledge['body'], $subscribeUrl);

        return $knowledge;
    }

    private function formatAccessData(&$body): void
    {
        $rules = [
            [
                'type' => 'regex',
                'pattern' => '/<!--access start-->(.*?)<!--access end-->/s',
                'replacement' => '<div class="v2board-no-access">' . __('You must have a valid subscription to view content in this area') . '</div>'
            ]
        ];

        $this->applyReplacementRules($body, $rules);
    }

    private function replacePlaceholders(string $body, string $subscribeUrl): string
    {
        $rules = [
            [
                'type' => 'string',
                'search' => '{{siteName}}',
                'replacement' => admin_setting('app_name', 'XBoard')
            ],
            [
                'type' => 'string',
                'search' => '{{subscribeUrl}}',
                'replacement' => $subscribeUrl
            ],
            [
                'type' => 'string',
                'search' => '{{urlEncodeSubscribeUrl}}',
                'replacement' => urlencode($subscribeUrl)
            ],
            [
                'type' => 'string',
                'search' => '{{safeBase64SubscribeUrl}}',
                'replacement' => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($subscribeUrl))
            ]
        ];

        $this->applyReplacementRules($body, $rules);
        return $body;
    }

    private function applyReplacementRules(string &$body, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'regex') {
                $body = preg_replace($rule['pattern'], $rule['replacement'], $body);
            } else {
                $body = str_replace($rule['search'], $rule['replacement'], $body);
            }
        }
    }
}
