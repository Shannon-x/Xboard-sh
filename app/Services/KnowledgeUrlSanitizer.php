<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

final class KnowledgeUrlSanitizer implements AttributeSanitizerInterface
{
    public function __construct(private ?string $baseUrl) {}

    public function getSupportedElements(): ?array { return ['a', 'img']; }
    public function getSupportedAttributes(): ?array { return ['href', 'src']; }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (str_starts_with($value, '#') && $attribute === 'href') {
            return $value;
        }
        if (parse_url($value, PHP_URL_SCHEME)) {
            return $value; // The preceding Symfony URL sanitizer checks schemes.
        }
        if (!$this->baseUrl || !filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            return null;
        }
        $base = new Uri($this->baseUrl);
        if (!in_array($base->getScheme(), ['https', 'http'], true) || $base->getUserInfo() !== '' ||
            $base->getQuery() !== '' || $base->getFragment() !== '' || !in_array($base->getPath(), ['', '/'], true)) {
            return null;
        }
        return (string) UriResolver::resolve($base->withPath('/'), new Uri($value));
    }
}
