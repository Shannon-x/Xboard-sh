<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payg\PaygService;
use Illuminate\Http\Request;

/** 上网按量：面板「扣费明细」。余额摘要随 getSubscribe 的 payg 下发，自动补充开关走 /user/update。 */
class PaygController extends Controller
{
    public function ledger(Request $request, PaygService $service)
    {
        return $this->success($service->ledger(User::findOrFail($request->user()->id)));
    }
}
