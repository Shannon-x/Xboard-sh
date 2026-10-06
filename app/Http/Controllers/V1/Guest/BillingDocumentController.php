<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\BillingDocument;
use App\Services\Billing\BillingArchive;
use App\Services\Billing\BillingDocumentService;

/**
 * 收据 / 账单 PDF 下载。<a href> 带不上 Bearer，凭据是 URL 里 128 位随机的 access_key（同工单附件）。
 */
class BillingDocumentController extends Controller
{
    public function download(int $id, string $key, BillingArchive $archive, BillingDocumentService $docs)
    {
        $doc = BillingDocument::find($id);
        if (!$doc || !hash_equals((string) $doc->access_key, $key)) {
            abort(404);
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
}
