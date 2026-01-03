<?php
declare(strict_types=1);

namespace Laas\Support\Search;

final class Highlighter
{
    /** @return array<int, array{text: string, mark: bool}> */
    public static function segments(string $text, string $query): array
    {
        $query = SearchNormalizer::normalize($query);
        if ($text === '' || $query === '') {
            return [['text' => $text, 'mark' => false]];
        }

        $segments = [];
        $offset = 0;
        $len = self::length($text);
        $qLen = self::length($query);

        while ($offset < $len) {
            $pos = self::ipos($text, $query, $offset);
            if ($pos === null) {
                $segments[] = [
                    'text' => self::substr($text, $offset, $len - $offset),
                    'mark' => false,
                ];
                break;
            }

            if ($pos > $offset) {
                $segments[] = [
                    'text' => self::substr($text, $offset, $pos - $offset),
                    'mark' => false,
                ];
            }

            $segments[] = [
                'text' => self::substr($text, $pos, $qLen),
                'mark' => true,
            ];

            $offset = $pos + $qLen;
        }

        return $segments;
    }

    /** @return array<int, array{text: string, mark: bool}> */
    public static function snippet(string $text, string $query, int $maxLen = 160): array
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($plain === '') {
            return [['text' => '', 'mark' => false]];
        }

        $query = SearchNormalizer::normalize($query);
        if ($query === '' || self::length($query) < 2) {
            $snippet = self::substr($plain, 0, $maxLen);
            if (self::length($plain) > $maxLen) {
                $snippet .= '...';
            }
            return self::segments($snippet, $query);
        }

        $pos = self::ipos($plain, $query, 0);
        if ($pos === null) {
            $snippet = self::substr($plain, 0, $maxLen);
            if (self::length($plain) > $maxLen) {
                $snippet .= '...';
            }
            return self::segments($snippet, $query);
        }

        $half = (int) floor($maxLen / 2);
        $start = max(0, $pos - $half);
        $snippet = self::substr($plain, $start, $maxLen);

        if ($start > 0) {
            $snippet = '...' . $snippet;
        }
        if (($start + $maxLen) < self::length($plain)) {
            $snippet .= '...';
        }

        return self::segments($snippet, $query);
    }

    private static function ipos(string $haystack, string $needle, int $offset): ?int
    {
        if (function_exists('mb_stripos')) {
            $pos = mb_stripos($haystack, $needle, $offset);
            return $pos === false ? null : (int) $pos;
        }

        $pos = stripos($haystack, $needle, $offset);
        return $pos === false ? null : (int) $pos;
    }

    private static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }

    private static function substr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length);
        }

        return substr($value, $start, $length);
    }
}
