<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

class KnowledgeNoStore
{
    public function handle($request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $handler = app(ExceptionHandler::class);
            $handler->report($error);
            $response = $handler->render($request, $error);
        }
        // Applies to successes, validation failures and denials alike. No shared
        // JSON cache can retain content after an administrator retracts it.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
