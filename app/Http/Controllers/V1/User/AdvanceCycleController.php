<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\AdvanceCycleService;
use Illuminate\Http\Request;

class AdvanceCycleController extends Controller
{
    public function __construct(
        private readonly AdvanceCycleService $advanceCycleService
    ) {
    }

    public function preview(Request $request)
    {
        return $this->success(
            $this->advanceCycleService->preview($request->user())
        );
    }

    public function advance(Request $request)
    {
        $result = $this->advanceCycleService->advance($request->user(), [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        if (!$result['eligible']) {
            $status = $result['reason'] === 'server_error' ? 500001 : 400200;
            return $this->fail([$status, $result['message']], $result);
        }

        return $this->success($result);
    }
}
