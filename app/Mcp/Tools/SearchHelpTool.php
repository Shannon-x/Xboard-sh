<?php

namespace App\Mcp\Tools;

use App\Models\Knowledge;
use App\Models\Notice;
use App\Models\User;
use App\Services\Mcp\McpPresenter as P;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_help')]
#[Title('帮助与公告')]
#[Description('搜索帮助文档（客户端怎么导入、怎么设置等）并返回最近的站点公告。不传关键词时只返回公告和文档目录。')]
#[IsReadOnly]
#[IsIdempotent]
class SearchHelpTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string()->description('关键词，例如「Clash」「iOS」「导入」。'),
            'language' => $schema->string()->description('文档语言，默认 zh-CN（也有 en-US 等）。'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $keyword = trim((string) ($request->get('keyword') ?? ''));
        $language = (string) ($request->get('language') ?: 'zh-CN');

        $query = Knowledge::where('show', 1)->where('language', $language)->orderBy('sort');
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, '%_\\') . '%';
            $query->where(fn ($q) => $q->where('title', 'LIKE', $like)->orWhere('body', 'LIKE', $like));
        }
        $articles = $query->limit($keyword !== '' ? 5 : 30)->get(['category', 'title', 'body', 'updated_at']);

        $notices = Notice::where('show', true)->orderBy('sort')->orderByDesc('id')->limit(5)->get(['title', 'content', 'created_at']);

        return self::result([
            'articles' => $articles->map(fn (Knowledge $k) => [
                'category' => (string) $k->category,
                'title' => (string) $k->title,
                // 正文里的订阅链接占位符一律不替换：订阅链接不进 AI 对话
                ...($keyword !== '' ? ['content' => P::scrubSubscribe(P::plain(preg_replace('/<!--access start-->.*?<!--access end-->/s', '', (string) $k->body), 1200))] : []),
            ])->values()->all(),
            'notices' => $notices->map(fn (Notice $n) => [
                'title' => (string) $n->title,
                'date' => P::date($n->created_at ? (int) $n->getRawOriginal('created_at') : null),
                'content' => P::plain($n->content, 500),
            ])->values()->all(),
        ]);
    }
}
