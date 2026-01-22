<?php

declare(strict_types=1);

namespace Laas\Http;

final class HeadlessMode
{
    public const DEFAULT_ALLOWLIST = ['/login', '/logout', '/admin'];
    public const DEFAULT_OVERRIDE_PARAM = '_html';

    public static function isEnabled(): bool
    {
        $value = $_ENV['APP_HEADLESS'] ?? null;
        if ($value === null || $value === '') {
            $value = $_ENV['HEADLESS_MODE'] ?? null;
        }
        if ($value === null || $value === '') {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? false;
    }

    public static function isHtmlRequested(Request $request): bool
    {
        $format = strtolower((string) ($request->query('format') ?? ''));
        if ($format === 'json') {
            return false;
        }
        if ($format === 'html') {
            return true;
        }

        if (self::hasOverrideParam($request)) {
            return true;
        }

        $accept = strtolower((string) ($request->getHeader('accept') ?? ''));
        return str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');
    }

    public static function isHtmlAllowed(Request $request): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        $path = self::normalizePath($request->getPath());
        foreach (self::allowlist() as $prefix) {
            if ($prefix === '/') {
                return true;
            }
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        if (self::hasOverrideParam($request)) {
            return self::isOverrideAllowed($request);
        }

        return false;
    }

    public static function shouldBlockHtml(Request $request): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (!self::isHtmlRequested($request)) {
            return false;
        }

        return !self::isHtmlAllowed($request);
    }

    public static function shouldDefaultJson(Request $request): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        return !self::isHtmlRequested($request);
    }

    /** @return array<string, mixed> */
    public static function buildNotAcceptablePayload(Request $request): array
    {
        $meta = [
            'format' => 'json',
            'route' => self::resolveRoute($request),
        ];
        $built = ErrorResponse::buildPayload($request, ErrorCode::FORMAT_NOT_ACCEPTABLE, [], 406, $meta, 'headless.mode');
        return $built['payload'];
    }

    public static function resolveRoute(Request $request): string
    {
        $pattern = $request->getAttribute('route.pattern');
        if (is_string($pattern) && $pattern !== '') {
            return $pattern;
        }

        $path = $request->getPath();
        return $path !== '' ? $path : '/';
    }

    /** @return array<int, string> */
    private static function allowlist(): array
    {
        $value = $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] ?? null;
        if ($value === null) {
            return self::DEFAULT_ALLOWLIST;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== '');
        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = self::normalizePath($part);
        }

        return $normalized;
    }

    private static function overrideParam(): string
    {
        $value = $_ENV['APP_HEADLESS_HTML_OVERRIDE_PARAM'] ?? null;
        if ($value === null) {
            return self::DEFAULT_OVERRIDE_PARAM;
        }

        return trim((string) $value);
    }

    private static function hasOverrideParam(Request $request): bool
    {
        $param = self::overrideParam();
        if ($param === '') {
            return false;
        }

        $raw = $request->query($param);
        if ($raw === null) {
            return false;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? false;
    }

    private static function isOverrideAllowed(Request $request): bool
    {
        if (!self::isDevEnv()) {
            return false;
        }

        $path = self::normalizePath($request->getPath());
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private static function isDevEnv(): bool
    {
        $env = $_ENV['APP_ENV'] ?? null;
        if ($env === null || $env === '') {
            $env = getenv('APP_ENV') ?: '';
        }

        return strtolower((string) $env) !== 'prod';
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
