<?php

declare(strict_types=1);

namespace Laas\Http;

use Laas\DevTools\DevToolsContext;
use Laas\Support\RequestScope;

final class RequestContext
{
    private static ?float $startedAt = null;
    private static array $metrics = [];

    public static function resetForTests(): void
    {
        if (!self::allowTestOverrides()) {
            return;
        }

        RequestScope::setRequest(null);
        RequestScope::reset();
        self::$startedAt = null;
        self::resetMetrics();
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['X_REQUEST_ID']);
    }

    public static function setForTests(string $requestId, string $path): void
    {
        if (!self::allowTestOverrides()) {
            return;
        }

        $requestId = trim($requestId);
        if ($requestId !== '') {
            RequestScope::set('request.id', $requestId);
            $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;
        }

        $path = trim($path);
        if ($path !== '') {
            $_SERVER['REQUEST_URI'] = $path;
        }
    }

    public static function requestId(): ?string
    {
        $fromScope = RequestScope::get('request.id');
        if (is_string($fromScope)) {
            $fromScope = trim($fromScope);
            if ($fromScope !== '') {
                return $fromScope;
            }
        }

        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $rid = $context->getRequestId();
            if (is_string($rid)) {
                $rid = trim($rid);
                if ($rid !== '') {
                    return $rid;
                }
            }
        }

        $request = RequestScope::getRequest();
        if ($request instanceof Request) {
            $rid = $request->getHeader('x-request-id');
            if (is_string($rid)) {
                $rid = trim($rid);
                if ($rid !== '') {
                    return $rid;
                }
            }
        }

        $rid = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['X_REQUEST_ID'] ?? null;
        if (!is_string($rid)) {
            $generated = bin2hex(random_bytes(16));
            RequestScope::set('request.id', $generated);
            return $generated;
        }
        $rid = trim($rid);
        if ($rid === '') {
            $generated = bin2hex(random_bytes(16));
            RequestScope::set('request.id', $generated);
            return $generated;
        }
        RequestScope::set('request.id', $rid);
        return $rid;
    }

    public static function path(): ?string
    {
        $request = RequestScope::getRequest();
        if ($request instanceof Request) {
            $path = trim((string) $request->getPath());
            return $path !== '' ? $path : null;
        }

        $raw = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $pos = strpos($raw, '?');
        if ($pos === false) {
            return $raw;
        }
        $path = substr($raw, 0, $pos);
        return $path !== '' ? $path : null;
    }

    public static function isDebug(): bool
    {
        $value = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? null;
        if ($value === null || $value === '') {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? false;
    }

    public static function timestamp(): string
    {
        return gmdate('c');
    }

    public static function setStartTime(float $startedAt): void
    {
        self::$startedAt = $startedAt;
    }

    public static function startTime(): ?float
    {
        return self::$startedAt;
    }

    /**
     * @return array<string, float|int>
     */
    public static function metrics(): array
    {
        return self::$metrics;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function setMetrics(array $metrics): void
    {
        if (!self::allowTestOverrides()) {
            return;
        }

        self::$metrics = self::normalizeMetrics($metrics);
    }

    public static function resetMetrics(): void
    {
        if (!self::allowTestOverrides()) {
            return;
        }

        self::$metrics = [];
    }

    private static function allowTestOverrides(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;
        if (is_string($env) && strtolower($env) === 'test') {
            return true;
        }

        return php_sapi_name() === 'cli';
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{total_ms: float, memory_mb: float, sql_count: int, sql_unique: int, sql_dup: int, sql_ms: float}
     */
    private static function normalizeMetrics(array $metrics): array
    {
        return [
            'total_ms' => (float) ($metrics['total_ms'] ?? 0),
            'memory_mb' => (float) ($metrics['memory_mb'] ?? 0),
            'sql_count' => (int) ($metrics['sql_count'] ?? 0),
            'sql_unique' => (int) ($metrics['sql_unique'] ?? 0),
            'sql_dup' => (int) ($metrics['sql_dup'] ?? 0),
            'sql_ms' => (float) ($metrics['sql_ms'] ?? 0),
        ];
    }
}
