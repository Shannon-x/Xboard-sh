<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
{
    /** 订阅 token 的规范形态。 */
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/i';

    /** 「32 位 token + 分隔符 + 被粘连的 query」。分隔符后面的内容按 query string 解析。 */
    private const GLUED_TOKEN_PATTERN = '/^([a-f0-9]{32})(?:[&?#](.*))?$/i';

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token', $request->route('token'));

        // 快路径：绝大多数请求在这里就通过，开销与修复前完全一致（一次正则）。
        if (!is_string($token) || !preg_match(self::TOKEN_PATTERN, $token)) {
            $token = $this->recoverGluedToken($request, $token);
        }

        if ($token === null) {
            throw new ApiException('token is null', 403);
        }

        $token = strtolower($token);

        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error', 403);
        }

        Auth::setUser($user);

        return $next($request);
    }

    /**
     * 还原被粘连进 token 的 query。
     *
     * 订阅地址是路径式的 /{subscribe_path}/{token}，本身不带 `?`。用户照旧版 V2board
     * 教程在末尾追加 `&flag=sing-box` 时，这段文字不会成为查询参数，而是整串落进路由的
     * {token} 里 —— token 校验必然失败，客户端拿到 403 `token is null`，sing-box 显示
     * "Failed to create profile"。生产日志里这一形态共 331 次、103 个不同账号，全部来自
     * sing-box 系客户端（SFI / SFA / SFM / HiddifyNext），最长的一个账号连撞两个半月。
     *
     * 这里只处理「32 位 token + 分隔符 + query」这种可以明确判定的形态：把 token 拆回来，
     * 并将粘连的参数补进 query，让用户本来想要的 flag / types / filter 正常生效。token 仍
     * 必须是精确 32 位十六进制且能在库中命中，鉴权强度不变；无法判定的（例如 token 本身
     * 少写或多写了字符）一律照旧拒绝，不做猜测。
     *
     * @param \Illuminate\Http\Request $request
     * @param mixed $token
     * @return string|null 还原后的 token，无法判定时返回 null
     */
    private function recoverGluedToken($request, $token): ?string
    {
        if (!is_string($token)) {
            return null;
        }

        // 顺手吃掉复制订阅链接时常见的首尾空白与换行。
        if (!preg_match(self::GLUED_TOKEN_PATTERN, trim($token), $matches)) {
            return null;
        }

        $recovered = $matches[1];
        $glued = $matches[2] ?? '';

        if ($glued !== '') {
            parse_str($glued, $params);
            // token 只认路径/查询里那一个来源，不允许被粘连串改写。
            unset($params['token']);

            foreach ($params as $key => $value) {
                // 显式写在 query 里的参数优先，粘连串只做补充。
                if (!$request->query->has($key)) {
                    $request->query->set($key, $value);
                }
            }
        }

        // 让下游（控制器、协议匹配、插件钩子）读到的同样是干净的 token。
        if ($route = $request->route()) {
            $route->setParameter('token', $recovered);
        }
        if ($request->query->has('token')) {
            $request->query->set('token', $recovered);
        }

        return $recovered;
    }
}
