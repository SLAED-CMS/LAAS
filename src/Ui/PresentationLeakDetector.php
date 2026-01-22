<?php

declare(strict_types=1);

namespace Laas\Ui;

final class PresentationLeakDetector
{
    /** @return array<int, array{key: string, path: string, code: string}> */
    public static function detectArray(array $data): array
    {
        $warnings = [];
        $stack = [[
            'path' => '',
            'data' => $data,
        ]];

        while ($stack !== []) {
            $current = array_pop($stack);
            $path = (string) ($current['path'] ?? '');
            $value = $current['data'] ?? null;
            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $key => $child) {
                if (!is_string($key)) {
                    if (is_array($child)) {
                        $stack[] = [
                            'path' => $path,
                            'data' => $child,
                        ];
                    }
                    continue;
                }
                $keyLower = strtolower($key);
                $fullPath = $path !== '' ? $path . '.' . $key : $key;

                $code = self::detectKey($keyLower);
                if ($code !== null) {
                    $warnings[] = [
                        'key' => self::normalizeKeyName($key),
                        'path' => $fullPath,
                        'code' => $code,
                    ];
                }

                if (is_array($child)) {
                    $stack[] = [
                        'path' => $fullPath,
                        'data' => $child,
                    ];
                }
            }
        }

        return $warnings;
    }

    public static function normalizeKeyName(string $key): string
    {
        $normalized = strtolower($key);
        $normalized = str_replace(['-', ' '], '_', $normalized);
        return $normalized;
    }

    private static function detectKey(string $keyLower): ?string
    {
        if (str_ends_with($keyLower, '_class') || str_contains($keyLower, 'class_')) {
            return 'class_key';
        }
        if (str_starts_with($keyLower, 'style_')) {
            return 'style_key';
        }
        if (str_starts_with($keyLower, 'htmx_')) {
            return 'htmx_key';
        }
        if (str_starts_with($keyLower, 'hx_')) {
            return 'hx_key';
        }
        if ($keyLower === 'onclick' || str_starts_with($keyLower, 'onclick_')) {
            return 'onclick_key';
        }
        if (str_starts_with($keyLower, 'data-bs-')) {
            return 'data_bs_key';
        }
        return null;
    }
}
