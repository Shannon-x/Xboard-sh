<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const REDACTED_VALUE = '[REDACTED]';
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'old_password',
        'new_password',
        'password_confirmation',
        'email_password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'private_key',
        'key_file',
        'server_key',
        'secret_key',
        'client_secret',
        'authorization',
        'auth_data',
        'server_token',
        'telegram_bot_token',
        'turnstile_secret_key',
        'recaptcha_v3_secret_key',
        'stripe_token',
    ];
    private const SENSITIVE_KEY_PATTERNS = [
        '/(?:^|[_-])auth(?:$|[_-])/i',
        '/(?:^|[_-])(password|passwd|pwd|token|secret)$/i',
        '/(?:^|[_-])(api|private|server|secret)[_-]key$/i',
    ];

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = $this->redactSensitiveData($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $data[$key] = self::REDACTED_VALUE;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redactSensitiveData($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
