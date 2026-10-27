<?php

namespace App\Mcp\Tools;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Mcp\McpAudit;
use App\Services\Mcp\McpConfig;
use App\Services\Mcp\McpTokenService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 客户 AI 助手工具的共同外壳：取当前用户与令牌、记审计、把业务异常变成给客户看的一句话。
 *
 * 子类只写 run()。输出一律走 McpPresenter 裁剪过的数组：没有订阅链接 / token / uuid / 节点地址，
 * 金额以「元」为单位的字符串，流量以 GB，时间是北京时间。
 */
abstract class McpTool extends Tool
{
    /**
     * 会改动账户的工具（下单、改设置、工单、兑换……）：只对客户勾选了「允许 AI 替我操作」的令牌（mcp:order）注册，
     * 后台关了「允许 AI 操作」时也不注册。只读令牌连工具名都看不到。
     */
    protected bool $ordering = false;

    /** 审计里记下的业务编号（下单类工具：订单号） */
    protected ?string $auditRef = null;

    /** 这次调用用的令牌（run() 里重新查用户后 currentAccessToken 会丢，用这个） */
    protected ?PersonalAccessToken $token = null;

    abstract protected function run(Request $request, User $user): Response|ResponseFactory;

    public function shouldRegister(Request $request): bool
    {
        if (!$this->ordering) {
            return true;
        }
        $token = $request->user()?->currentAccessToken();
        return McpConfig::fromSettings()->orderEnabled
            && McpTokenService::canOrder($token instanceof PersonalAccessToken ? $token : null);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User|null $user */
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (!$user || !AuthService::isMcpToken($token)) {
            return Response::error('令牌无效，请在面板「AI 助手」里重新生成。');
        }
        $this->auditRef = null;
        $this->token = $token;
        $ip = request()->ip();
        try {
            if ($this->ordering && !$this->shouldRegister($request)) {
                throw new ApiException('这个令牌是只读的：要让 AI 替你下单或修改设置，请在面板「AI 助手」里生成一个勾选了「允许 AI 替我操作」的令牌。');
            }
            $response = $this->run($request, $user);
            McpAudit::record($user->id, (int) $token->id, $this->name(), !self::failed($response), $this->auditRef, null, $ip);
            return $response;
        } catch (ApiException $e) {
            McpAudit::record($user->id, (int) $token->id, $this->name(), false, $this->auditRef, $e->getMessage(), $ip);
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: '参数不正确';
            McpAudit::record($user->id, (int) $token->id, $this->name(), false, null, $message, $ip);
            return Response::error($message);
        } catch (\Throwable $e) {
            Log::error('mcp tool failed', ['tool' => $this->name(), 'user_id' => $user->id, 'error' => $e->getMessage()]);
            McpAudit::record($user->id, (int) $token->id, $this->name(), false, $this->auditRef, 'internal: ' . $e->getMessage(), $ip);
            return Response::error('服务暂时出错，请稍后再试，或到面板里操作。');
        }
    }

    /**
     * 限流。给了 $panelLimiter（RouteServiceProvider 里的限流器名）时与面板同名接口共用一个桶：
     * 键按 ThrottleRequests 的规则算成 md5(限流器名 . byUserOrIp 键)，换成 AI 调也不会多出一份额度，
     * 比如礼品卡兑换码不能借 AI 多试几次。
     */
    protected static function throttle(string $key, int $max, int $decaySeconds, string $message, ?string $panelLimiter = null): void
    {
        if ($panelLimiter !== null) {
            $key = md5($panelLimiter . $key);
        }
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ApiException($message);
        }
        RateLimiter::hit($key, $decaySeconds);
    }

    /** 改动类工具的二次确认：没带 confirm=true 时只说明后果，不动账户 */
    protected static function confirmed(Request $request): bool
    {
        return filter_var($request->get('confirm'), FILTER_VALIDATE_BOOLEAN);
    }

    /** 结构化结果：同一份数据既给 structuredContent，也镜像成一段 JSON 文本（老客户端只认文本） */
    protected static function result(array $data): ResponseFactory
    {
        return Response::structured($data);
    }

    private static function failed(Response|ResponseFactory $response): bool
    {
        return $response instanceof Response
            ? $response->isError()
            : $response->responses()->contains(fn (Response $r) => $r->isError());
    }
}
