<?php

namespace App\Services\Mcp;

use RuntimeException;

/**
 * 一键授权里按 OAuth 规范回给 AI 软件的错误：{"error": "...", "error_description": "..."}。
 * 这些话是给软件看的（ChatGPT 等会自己换成界面文字），所以用英文。
 */
class McpOAuthError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
