<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\GiftCardCheckRequest;
use App\Http\Requests\User\GiftCardRedeemRequest;
use App\Models\GiftCardUsage;
use App\Services\GiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GiftCardController extends Controller
{
    /**
     * 礼品卡兑换码是 bearer instrument（持有即可兑换），不能明文落日志。
     * 这里只保留首 4 + 末 2 位，中间打码；调试时可凭 sha256 短哈希做比对。
     */
    private static function maskCode(?string $code): array
    {
        $code = (string) $code;
        $len = strlen($code);
        $masked = $len <= 6
            ? str_repeat('*', max(0, $len))
            : substr($code, 0, 4) . str_repeat('*', max(2, $len - 6)) . substr($code, -2);
        return [
            'code_mask' => $masked,
            'code_hash8' => substr(hash('sha256', $code), 0, 8),
        ];
    }

    /**
     * 查询兑换码信息
     */
    public function check(GiftCardCheckRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());

            // 1. 验证礼品卡本身是否有效 (如不存在、已过期、已禁用)
            $giftCardService->validateIsActive();

            // 2. 检查用户是否满足使用条件，但不在此处抛出异常
            $eligibility = $giftCardService->checkUserEligibility();

            // 3. 获取卡片信息和奖励预览
            $codeInfo = $giftCardService->getCodeInfo();
            $rewardPreview = $giftCardService->previewRewards();

            return $this->success([
                'code_info' => $codeInfo, // 这里面已经包含 plan_info
                'reward_preview' => $rewardPreview,
                'can_redeem' => $eligibility['can_redeem'],
                'reason' => $eligibility['reason'],
            ]);

        } catch (ApiException $e) {
            // 这里只捕获 validateIsActive 抛出的异常
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('礼品卡查询失败', [
                ...self::maskCode($request->input('code')),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '查询失败，请稍后重试']);
        }
    }

    /**
     * 使用兑换码
     */
    public function redeem(GiftCardRedeemRequest $request)
    {
        try {
            $giftCardService = new GiftCardService($request->input('code'));
            $giftCardService->setUser($request->user());
            $giftCardService->validate();

            // 使用礼品卡
            $result = $giftCardService->redeem([
                // 'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('礼品卡使用成功', [
                ...self::maskCode($request->input('code')),
                'user_id' => $request->user()->id,
                'rewards' => $result['rewards'],
            ]);

            return $this->success([
                'message' => '兑换成功！',
                'rewards' => $result['rewards'],
                'invite_rewards' => $result['invite_rewards'],
                'template_name' => $result['template_name'],
            ]);

        } catch (ApiException $e) {
            return $this->fail([400, $e->getMessage()]);
        } catch (\Exception $e) {
            // 不写 getTraceAsString —— PHP trace 会把 GiftCardService::__construct(string $code) 的实参一并打印，
            // 等同把兑换码明文落日志两次。stack 由 Laravel 的全局 ExceptionHandler 在 storage/logs 里完整记录，
            // 这里只补充业务上下文即可。
            Log::error('礼品卡使用失败', [
                ...self::maskCode($request->input('code')),
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, '兑换失败，请稍后重试']);
        }
    }

    /**
     * 获取用户兑换记录
     */
    public function history(Request $request)
    {
        $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $perPage = $request->input('per_page', 15);

        $usages = GiftCardUsage::with(['template', 'code'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $usages->getCollection()->map(function (GiftCardUsage $usage) {
            return [
                'id' => $usage->id,
                'code' => ($usage->code instanceof \App\Models\GiftCardCode && $usage->code->code)
                    ? (substr($usage->code->code, 0, 8) . '****')
                    : '',
                'template_name' => $usage->template->name ?? '',
                'template_type' => $usage->template->type ?? '',
                'template_type_name' => $usage->template->type_name ?? '',
                'rewards_given' => $usage->rewards_given,
                'invite_rewards' => $usage->invite_rewards,
                'multiplier_applied' => $usage->multiplier_applied,
                'created_at' => $usage->created_at,
            ];
        })->values();
        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $usages->currentPage(),
                'last_page' => $usages->lastPage(),
                'per_page' => $usages->perPage(),
                'total' => $usages->total(),
            ],
        ]);
    }

    /**
     * 获取兑换记录详情
     */
    public function detail(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:v2_gift_card_usage,id',
        ]);

        $usage = GiftCardUsage::with(['template', 'code', 'inviteUser'])
            ->where('user_id', $request->user()->id)
            ->where('id', $request->input('id'))
            ->first();

        if (!$usage) {
            return $this->fail([404, '记录不存在']);
        }

        return $this->success([
            'id' => $usage->id,
            'code' => $usage->code->code ?? '',
            'template' => [
                'name' => $usage->template->name ?? '',
                'description' => $usage->template->description ?? '',
                'type' => $usage->template->type ?? '',
                'type_name' => $usage->template->type_name ?? '',
                'icon' => $usage->template->icon ?? '',
                'theme_color' => $usage->template->theme_color ?? '',
            ],
            'rewards_given' => $usage->rewards_given,
            'invite_rewards' => $usage->invite_rewards,
            'invite_user' => $usage->inviteUser ? [
                'id' => $usage->inviteUser->id ?? '',
                'email' => isset($usage->inviteUser->email) ? (substr($usage->inviteUser->email, 0, 3) . '***@***') : '',
            ] : null,
            'user_level_at_use' => $usage->user_level_at_use,
            'plan_id_at_use' => $usage->plan_id_at_use,
            'multiplier_applied' => $usage->multiplier_applied,
            // 'ip_address' => $usage->ip_address,
            'notes' => $usage->notes,
            'created_at' => $usage->created_at,
        ]);
    }

    /**
     * 获取可用的礼品卡类型
     */
    public function types(Request $request)
    {
        return $this->success([
            'types' => \App\Models\GiftCardTemplate::getTypeMap(),
        ]);
    }
}
