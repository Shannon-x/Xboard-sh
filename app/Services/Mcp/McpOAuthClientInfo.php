<?php

namespace App\Services\Mcp;

/** 一个能走一键授权的 AI 软件（动态注册的，或元数据文档里的），见 McpOAuthClients::find() */
final class McpOAuthClientInfo
{
    /**
     * @param list<string> $redirectUris
     * @param string $authMethod none | client_secret_post | client_secret_basic
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $name,
        public readonly array $redirectUris,
        public readonly string $authMethod,
        public readonly ?string $secretHash,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->authMethod === 'none';
    }
}
