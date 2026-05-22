<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
{
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
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
            throw new ApiException('token is null',403);
        }
        $token = strtolower($token);

        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error',403);
        }
        
        Auth::setUser($user);
        return $next($request);
    }
}
