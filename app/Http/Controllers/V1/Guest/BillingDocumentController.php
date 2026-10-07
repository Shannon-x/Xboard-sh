<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;

/**
 * 收据 / 账单 PDF 下载。<a href> 带不上 Bearer，凭据在 URL 里：key = 8 位十六进制过期时间 + 32 位签名
 * （见 BillingDocument::downloadPath）。先验签再看过期：没有签名的人连「这个 id 存在、链接过期了」都问不出来。
 */
class BillingDocumentController extends Controller
{
    public function download(int $id, string $key, BillingArchive $archive, BillingDocumentService $docs)
    {
        $doc = BillingDocument::find($id);
        $expires = (int) hexdec(substr($key, 0, 8));
        if (!$doc || !hash_equals($doc->linkSignature($expires), substr($key, 8))) {
            abort(404);
        }
        if ($expires < time()) {
            return $this->expired($doc);
        }
        $pdf = $archive->contents($doc, $docs);
        if ($pdf === null) {
            abort(404);
        }
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $doc->doc_no . '.pdf"',
            'Content-Length' => (string) strlen($pdf),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** 链接在新标签页里打开，过期了给一页能看懂的说明和回面板的入口，而不是一段 JSON。 */
    private function expired(BillingDocument $doc)
    {
        [$title, $body, $back] = BillingDocumentService::withLocale(fn () => [
            __('billing.download.expired_title'),
            __('billing.download.expired_body'),
            __('billing.download.back'),
        ], $doc->locale ?: null);
        // 没配 app_url 时不给链接:相对路径会落到 API 域名上,点了也是 404
        $appUrl = rtrim((string) admin_setting('app_url', ''), '/');
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . '</title></head>'
            . '<body style="margin:0;background:#f6f1e9;color:#2b2420;font:16px/1.7 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">'
            . '<main style="max-width:32rem;margin:18vh auto;padding:0 1.25rem">'
            . '<h1 style="font-size:1.25rem;margin:0 0 .5rem">' . e($title) . '</h1>'
            . '<p style="margin:0 0 1.25rem;color:#6b5f55">' . e($body) . '</p>'
            . ($appUrl !== '' ? '<a href="' . e($appUrl . '/billing') . '" style="color:#c94f2e">' . e($back) . '</a>' : '')
            . '</main></body></html>';
        return response($html, 410, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
