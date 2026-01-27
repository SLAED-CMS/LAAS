<?php

declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Support\RequestScope;

final class ModulesDiscoveryStats
{
    private const KEY = 'devtools.modules';
    private const META_KEY = 'devtools.modules_meta';

    public static function record(string $bucket, float $ms, int $count = 0): void
    {
        $stats = RequestScope::get(self::KEY);
        if (!is_array($stats)) {
            $stats = [];
        }

        $stats = self::normalize($stats);
        self::bump($stats, $bucket, $ms, $count);
        self::bump($stats, 'total', $ms, $count);

        RequestScope::set(self::KEY, $stats);
    }

    public static function recordMeta(string $key, string $value): void
    {
        $meta = RequestScope::get(self::META_KEY);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta[$key] = $value;
        RequestScope::set(self::META_KEY, $meta);

        $stats = RequestScope::get(self::KEY);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats = self::normalize($stats);
        RequestScope::set(self::KEY, $stats);
    }

    /**
     * @param array<string, array{calls?: int, ms?: float, count?: int}> $stats
     */
    private static function bump(array &$stats, string $bucket, float $ms, int $count): void
    {
        $entry = $stats[$bucket] ?? [
            'calls' => 0,
            'ms' => 0.0,
            'count' => 0,
        ];

        $entry['calls'] = (int) ($entry['calls'] ?? 0) + 1;
        $entry['ms'] = (float) ($entry['ms'] ?? 0.0) + $ms;
        $entry['count'] = (int) ($entry['count'] ?? 0) + $count;

        $stats[$bucket] = $entry;
    }

    /**
     * @param array<string, array{calls?: int, ms?: float, count?: int}> $stats
     * @return array<string, array{calls: int, ms: float, count: int}>
     */
    private static function normalize(array $stats): array
    {
        $defaults = self::defaults();
        foreach ($defaults as $bucket => $entry) {
            $current = $stats[$bucket] ?? [];
            $stats[$bucket] = [
                'calls' => (int) ($current['calls'] ?? $entry['calls']),
                'ms' => (float) ($current['ms'] ?? $entry['ms']),
                'count' => (int) ($current['count'] ?? $entry['count']),
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, array{calls: int, ms: float, count: int}>
     */
    private static function defaults(): array
    {
        return [
            'discover' => ['calls' => 0, 'ms' => 0.0, 'count' => 0],
            'sync' => ['calls' => 0, 'ms' => 0.0, 'count' => 0],
            'catalog' => ['calls' => 0, 'ms' => 0.0, 'count' => 0],
            'admin_nav' => ['calls' => 0, 'ms' => 0.0, 'count' => 0],
            'total' => ['calls' => 0, 'ms' => 0.0, 'count' => 0],
        ];
    }
}
