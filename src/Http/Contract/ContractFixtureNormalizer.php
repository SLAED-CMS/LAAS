<?php

declare(strict_types=1);

namespace Laas\Http\Contract;

use Laas\Support\UrlSanitizer;

final class ContractFixtureNormalizer
{
    private const TIME_KEYS = [
        'created_at',
        'updated_at',
        'ts',
        'expires_at',
        'last_used_at',
        'deleted_at',
        'revoked_at',
        'started_at',
        'ended_at',
    ];
    private const ID_KEYS = [
        'id',
        'user_id',
        'role_id',
        'permission_id',
        'media_id',
        'page_id',
        'module_id',
        'token_id',
    ];
    private const URL_KEYS = ['url', 'href', 'redirect', 'location', 'link'];
    private const PATH_KEYS = ['file', 'path', 'file_path', 'stack', 'trace'];

    /** @return array<string, mixed> */
    public static function normalize(array $payload): array
    {
        $normalized = self::normalizeValue($payload, '');
        return is_array($normalized) ? $normalized : [];
    }

    private static function normalizeValue(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            $out = [];
            if (!self::isList($value)) {
                ksort($value);
            }
            foreach ($value as $childKey => $childValue) {
                $childKeyStr = is_string($childKey) ? $childKey : (string) $childKey;
                $out[$childKey] = self::normalizeValue($childValue, $childKeyStr);
            }
            return $out;
        }

        if ($key === 'request_id') {
            return '__REQ__';
        }

        if (in_array($key, self::TIME_KEYS, true)) {
            return '__TIME__';
        }

        if (in_array($key, self::ID_KEYS, true) && self::isNumericId($value)) {
            return '__ID__';
        }

        if ($key === 'route') {
            return $value;
        }

        if (in_array($key, self::PATH_KEYS, true) && is_string($value) && $value !== '') {
            return '__PATH__';
        }

        if (is_string($value)) {
            if (in_array($key, self::URL_KEYS, true)) {
                return self::sanitizeUrl($value);
            }
            if (self::looksLikeUrl($value)) {
                return self::sanitizeUrl($value);
            }
            if (self::looksLikePath($value)) {
                return '__PATH__';
            }
            if (self::looksLikeRelativeTime($value)) {
                return '__TIME__';
            }
        }

        return $value;
    }

    private static function isNumericId(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return true;
        }
        return false;
    }

    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function looksLikeUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (!str_contains($value, '://')) {
            return false;
        }
        $parts = parse_url($value);
        return is_array($parts) && isset($parts['host']);
    }

    private static function sanitizeUrl(string $value): string
    {
        return UrlSanitizer::sanitizeUrl($value);
    }

    private static function looksLikePath(string $value): bool
    {
        return preg_match('#^(?:[A-Za-z]:\\\\|/)#', $value) === 1;
    }

    private static function looksLikeRelativeTime(string $value): bool
    {
        return preg_match('#\\b\\d+\\s*(seconds?|minutes?|hours?|days?|weeks?)\\s*ago\\b#i', $value) === 1;
    }
}
