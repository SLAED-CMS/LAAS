<?php
declare(strict_types=1);

namespace Laas\Theme;

final class ThemeCapabilities
{
    /** @var array<int, string> */
    private const ALLOWLIST = [
        'toasts',
        'devtools',
        'headless',
        'blocks',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowlist(): array
    {
        return self::ALLOWLIST;
    }

    /**
     * @param array<int, mixed> $caps
     * @return array<int, string>
     */
    public static function normalize(array $caps): array
    {
        $out = [];
        $seen = [];
        foreach ($caps as $cap) {
            if (!is_string($cap)) {
                continue;
            }
            $cap = strtolower(trim($cap));
            if ($cap === '') {
                continue;
            }
            if (isset($seen[$cap])) {
                continue;
            }
            $seen[$cap] = true;
            $out[] = $cap;
        }
        return $out;
    }

    /**
     * @param array<int, string> $caps
     * @return array<string, bool>
     */
    public static function toMap(array $caps): array
    {
        $map = [];
        foreach ($caps as $cap) {
            $map[$cap] = true;
        }
        return $map;
    }
}
