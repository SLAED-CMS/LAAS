<?php
declare(strict_types=1);

namespace Laas\Database;

final class DbSqlFingerprint
{
    public static function normalize(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $sql) ?? $sql;
    }

    public static function fingerprint(string $sql, int $paramsCount = 0): string
    {
        $normalized = self::normalize($sql);
        if ($normalized === '') {
            return '';
        }

        $normalized = self::replaceLiterals($normalized);

        return $normalized;
    }

    private static function replaceLiterals(string $sql): string
    {
        $sql = preg_replace("/'(?:''|\\\\'|[^'])*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![A-Za-z_])0x[0-9A-Fa-f]+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![A-Za-z_])[-+]?\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;

        return $sql;
    }
}
