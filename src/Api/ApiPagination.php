<?php
declare(strict_types=1);

namespace Laas\Api;

final class ApiPagination
{
    public static function page(?string $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (!ctype_digit($value)) {
            return 1;
        }
        return max(1, (int) $value);
    }

    public static function perPage(?string $value): int
    {
        if ($value === null || $value === '') {
            return 10;
        }
        if (!ctype_digit($value)) {
            return 10;
        }
        $perPage = (int) $value;
        return max(1, min(50, $perPage));
    }

    public static function meta(int $page, int $perPage, int $total): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $total = max(0, $total);
        $hasMore = $page * $perPage < $total;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $hasMore,
        ];
    }
}
