<?php

declare(strict_types=1);

namespace Laas\Support\Rbac;

final class PermissionGrouper
{
    private const ORDER = [
        'admin',
        'pages',
        'menus',
        'media',
        'audit',
        'debug',
        'system',
        'users',
        'other',
    ];

    /** @param array<int, array{name: string, title: string|null}> $permissions */
    public function group(array $permissions): array
    {
        $grouped = [];
        foreach ($permissions as $perm) {
            $name = (string) ($perm['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $prefix = $this->prefix($name);
            $grouped[$prefix][] = [
                'name' => $name,
                'title' => $perm['title'] ?? null,
            ];
        }

        foreach ($grouped as $prefix => $items) {
            usort($items, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $grouped[$prefix] = $items;
        }

        $ordered = [];
        foreach (self::ORDER as $prefix) {
            if (isset($grouped[$prefix])) {
                $ordered[$prefix] = $grouped[$prefix];
            }
        }

        foreach ($grouped as $prefix => $items) {
            if (!isset($ordered[$prefix])) {
                $ordered[$prefix] = $items;
            }
        }

        return $ordered;
    }

    private function prefix(string $name): string
    {
        $parts = explode('.', $name, 2);
        $prefix = $parts[0] ?? '';
        return in_array($prefix, self::ORDER, true) ? $prefix : 'other';
    }
}
