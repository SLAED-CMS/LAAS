<?php

declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Content\ContentNormalizer;
use Laas\Security\ContentProfiles;
use Laas\Support\Cache\CacheInterface;

final class JsErrorInbox
{
    private const MAX_EVENTS = 200;
    private const RING_TTL = 600; // 10 minutes

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private CacheInterface $cache,
        private int $userId,
        private array $config = [],
        private ?ContentNormalizer $contentNormalizer = null
    ) {
    }

    public function add(array $event): void
    {
        $event = $this->sanitizeEvent($event);
        $list = $this->list(self::MAX_EVENTS);

        // Ring buffer: drop oldest if limit reached
        if (count($list) >= self::MAX_EVENTS) {
            array_shift($list);
        }

        $list[] = $event;

        $key = $this->cacheKey();
        $this->cache->set($key, $list, self::RING_TTL);
    }

    public function list(int $limit = 200): array
    {
        $key = $this->cacheKey();
        $data = $this->cache->get($key);

        if (!is_array($data)) {
            return [];
        }

        return array_slice($data, -$limit);
    }

    public function clear(): void
    {
        $key = $this->cacheKey();
        $this->cache->delete($key);
    }

    private function cacheKey(): string
    {
        return sprintf('devtools:js-errors:v1:%d', $this->userId);
    }

    private function sanitizeEvent(array $event): array
    {
        $type = (string) ($event['type'] ?? 'error');
        $message = $this->maskSensitive((string) ($event['message'] ?? ''));
        $source = $this->maskSensitive((string) ($event['source'] ?? ''));
        $stack = $this->maskSensitive((string) ($event['stack'] ?? ''));
        $url = $this->maskSensitive($this->sanitizeUrl((string) ($event['url'] ?? '')));
        $userAgent = $this->maskSensitive((string) ($event['userAgent'] ?? ''));
        $line = (int) ($event['line'] ?? 0);
        $column = (int) ($event['column'] ?? 0);
        $happenedAt = (int) ($event['happened_at'] ?? time() * 1000);

        if ($this->normalizeEnabled()) {
            $message = $this->normalizeField($message);
            $source = $this->normalizeField($source);
            $stack = $this->normalizeField($stack);
            $url = $this->normalizeField($url);
            $userAgent = $this->normalizeField($userAgent);
        }

        // Size limits
        $message = substr($message, 0, 500);
        $stack = substr($stack, 0, 4000);
        $source = substr($source, 0, 300);
        $userAgent = substr($userAgent, 0, 300);

        return [
            'type' => $type,
            'message' => $message,
            'source' => $source,
            'line' => $line,
            'column' => $column,
            'stack' => $stack,
            'url' => $url,
            'userAgent' => $userAgent,
            'happened_at' => $happenedAt,
            'received_at' => time(),
        ];
    }

    private function sanitizeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return '';
        }

        $scheme = (string) ($parsed['scheme'] ?? 'http');
        $host = (string) ($parsed['host'] ?? '');
        $path = (string) ($parsed['path'] ?? '/');
        $fragment = ''; // Drop fragment

        // Drop query (may contain tokens)
        return $scheme . '://' . $host . $path . $fragment;
    }

    private function maskSensitive(string $str): string
    {
        // First pass: mask Bearer tokens
        $str = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer ***', $str) ?? $str;

        // Second pass: mask other sensitive key=value or key: value pairs
        $str = preg_replace('/\b(token|csrf|secret|authorization|cookie|api[_-]?key|session[_-]?id|password|apikey)[=:\s]+[^\s&;,\'"\)]+/i', '${1}=***', $str) ?? $str;

        // Third pass: mask long hex/base64 strings (likely tokens)
        $str = preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '***', $str) ?? $str;

        return $str;
    }

    private function normalizeEnabled(): bool
    {
        $appConfig = $this->config['app'] ?? $this->config;
        return (bool) ($appConfig['devtools_js_normalize_enabled'] ?? false);
    }

    private function normalizeField(string $value): string
    {
        if ($value === '' || $this->contentNormalizer === null) {
            return $value;
        }

        return $this->contentNormalizer->normalize($value, 'html', ContentProfiles::USER_PLAIN);
    }
}
