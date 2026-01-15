<?php
declare(strict_types=1);

namespace Laas\Ai;

final class FileChangeApplier
{
    private string $baseDir;
    /** @var array<int, string> */
    private array $allowlist;

    /**
     * @param array<int, string> $allowlistPrefixes
     */
    public function __construct(
        ?string $baseDir = null,
        ?array $allowlistPrefixes = null
    ) {
        $root = $baseDir ?? dirname(__DIR__, 2);
        $this->baseDir = rtrim($root, '/\\');
        $configAllowlist = $allowlistPrefixes ?? $this->loadAllowlistFromConfig($root);
        if ($configAllowlist === null) {
            $configAllowlist = [
                'modules/',
                'themes/',
                'docs/',
                'storage/sandbox/modules/',
                'storage/sandbox/themes/',
                'storage/sandbox/docs/',
            ];
        }
        $this->allowlist = $this->normalizeAllowlist($configAllowlist);
    }

    /**
     * @param array<int, array<string, mixed>> $fileChanges
     * @return array{applied: int, would_apply: int, errors: int, items: array<int, array{op: string, path: string, status: string}>}
     */
    public function apply(array $fileChanges, bool $dryRun, bool $yes): array
    {
        $summary = [
            'applied' => 0,
            'would_apply' => 0,
            'errors' => 0,
            'items' => [],
        ];

        if (!$dryRun && !$yes) {
            $summary['errors'] = 1;
            $summary['items'][] = [
                'op' => '',
                'path' => '',
                'status' => 'refused',
            ];
            return $summary;
        }

        foreach ($fileChanges as $change) {
            if (!is_array($change)) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => '',
                    'path' => '',
                    'status' => 'invalid',
                ];
                continue;
            }

            $op = strtolower(trim((string) ($change['op'] ?? '')));
            $pathRaw = (string) ($change['path'] ?? '');
            $content = $change['content'] ?? null;

            if (!in_array($op, ['create', 'update'], true)) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $pathRaw,
                    'status' => 'invalid_op',
                ];
                continue;
            }

            $normalized = $this->normalizePath($pathRaw);
            $pathError = $this->validatePath($normalized);
            if ($pathError !== null) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => $pathError,
                ];
                continue;
            }

            if (!$this->isAllowed($normalized)) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'disallowed_path',
                ];
                continue;
            }

            if ($content === null) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'missing_content',
                ];
                continue;
            }

            $content = (string) $content;
            $fullPath = $this->baseDir . '/' . $normalized;

            if ($op === 'create' && is_file($fullPath)) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'exists',
                ];
                continue;
            }

            if ($op === 'update' && !is_file($fullPath)) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'missing',
                ];
                continue;
            }

            if ($dryRun) {
                $summary['would_apply']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'would_' . $op,
                ];
                continue;
            }

            if ($op === 'create') {
                $dir = dirname($fullPath);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    $summary['errors']++;
                    $summary['items'][] = [
                        'op' => $op,
                        'path' => $normalized,
                        'status' => 'mkdir_failed',
                    ];
                    continue;
                }
            }

            $bytes = file_put_contents($fullPath, $content, LOCK_EX);
            if ($bytes === false) {
                $summary['errors']++;
                $summary['items'][] = [
                    'op' => $op,
                    'path' => $normalized,
                    'status' => 'write_failed',
                ];
                continue;
            }

            $summary['applied']++;
            $summary['items'][] = [
                'op' => $op,
                'path' => $normalized,
                'status' => $op === 'create' ? 'created' : 'updated',
            ];
        }

        return $summary;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return $path;
    }

    private function validatePath(string $path): ?string
    {
        if ($path === '') {
            return 'invalid_path';
        }
        if (str_starts_with($path, '/')) {
            return 'absolute_path';
        }
        if (strpos($path, ':') !== false) {
            return 'drive_path';
        }
        if (strpos($path, '..') !== false) {
            return 'path_traversal';
        }

        return null;
    }

    private function isAllowed(string $path): bool
    {
        foreach ($this->allowlist as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $allowlistPrefixes
     * @return array<int, string>
     */
    private function normalizeAllowlist(array $allowlistPrefixes): array
    {
        $result = [];
        foreach ($allowlistPrefixes as $prefix) {
            if (!is_string($prefix)) {
                continue;
            }
            $prefix = str_replace('\\', '/', trim($prefix));
            if ($prefix === '') {
                continue;
            }
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $result[] = $prefix;
        }

        return array_values(array_unique($result));
    }

    private function loadAllowlistFromConfig(string $root): ?array
    {
        $path = $root . '/config/security.php';
        if (!is_file($path)) {
            return null;
        }
        $config = require $path;
        if (!is_array($config)) {
            return null;
        }
        $prefixes = $config['ai_file_apply_allowlist_prefixes'] ?? null;
        if (!is_array($prefixes)) {
            return null;
        }

        return $prefixes;
    }
}
