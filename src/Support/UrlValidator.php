<?php
declare(strict_types=1);

namespace Laas\Support;

final class UrlValidator
{
    public static function isSafe(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $value = trim($url);
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//');
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            return true;
        }

        if (in_array($scheme, ['javascript', 'data', 'vbscript'], true)) {
            return false;
        }

        return false;
    }
}
