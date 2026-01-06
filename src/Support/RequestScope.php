<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Http\Request;

final class RequestScope
{
    private static ?Request $request = null;
    private static array $keys = [];
    private static array $fallback = [];

    public static function setRequest(?Request $request): void
    {
        self::$request = $request;
    }

    public static function reset(): void
    {
        self::$keys = [];
        self::$fallback = [];
    }

    public static function has(string $key): bool
    {
        if (self::$request !== null) {
            return array_key_exists($key, self::$keys);
        }

        return array_key_exists($key, self::$fallback);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::has($key)) {
            return $default;
        }

        if (self::$request !== null) {
            return self::$request->getAttribute($key);
        }

        return self::$fallback[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$keys[$key] = true;
        if (self::$request !== null) {
            self::$request->setAttribute($key, $value);
            return;
        }

        self::$fallback[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset(self::$keys[$key], self::$fallback[$key]);
        if (self::$request !== null) {
            self::$request->setAttribute($key, null);
        }
    }
}
