<?php
declare(strict_types=1);

namespace Laas\Support\Search;

final class SearchNormalizer
{
    public static function normalize(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $query = preg_replace('/\s+/', ' ', $query) ?? $query;
        return trim($query);
    }

    public static function isTooShort(string $query, int $min = 2): bool
    {
        if ($query === '') {
            return false;
        }

        return self::length($query) < $min;
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }
}
