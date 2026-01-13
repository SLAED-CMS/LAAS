<?php
declare(strict_types=1);

namespace Laas\Support;

final class ConfigSanityChecker
{
    /** @return array<int, string> */
    public function check(array $config): array
    {
        $errors = [];
        $storage = $config['storage'] ?? null;
        if (!is_array($storage)) {
            $errors[] = 'storage config missing';
        } else {
            $errors = array_merge($errors, $this->checkStorage($storage));
        }

        $media = $config['media'] ?? null;
        if (!is_array($media)) {
            $errors[] = 'media config missing';
        } else {
            $errors = array_merge($errors, $this->checkMedia($media));
        }

        $perf = $config['perf'] ?? null;
        if (is_array($perf)) {
            $errors = array_merge($errors, $this->checkPerf($perf));
        }

        $cache = $config['cache'] ?? null;
        if (is_array($cache)) {
            $errors = array_merge($errors, $this->checkCache($cache));
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkStorage(array $storage): array
    {
        $errors = [];
        $raw = strtolower((string) ($storage['default_raw'] ?? $storage['default'] ?? ''));
        $default = strtolower((string) ($storage['default'] ?? ''));
        if (!in_array($raw, ['local', 's3'], true)) {
            $errors[] = 'storage.default_raw invalid';
        }
        if (!in_array($default, ['local', 's3'], true)) {
            $errors[] = 'storage.default invalid';
        }

        if ($default === 's3') {
            $s3 = $storage['disks']['s3'] ?? null;
            if (!is_array($s3)) {
                $errors[] = 'storage.disks.s3 missing';
                return $errors;
            }

            if (($s3['region'] ?? '') === '') {
                $errors[] = 'storage.s3.region missing';
            }
            if (($s3['bucket'] ?? '') === '') {
                $errors[] = 'storage.s3.bucket missing';
            }
            if (($s3['access_key'] ?? '') === '') {
                $errors[] = 'storage.s3.access_key missing';
            }
            if (($s3['secret_key'] ?? '') === '') {
                $errors[] = 'storage.s3.secret_key missing';
            }
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkMedia(array $media): array
    {
        $errors = [];
        $maxBytes = $media['max_bytes'] ?? null;
        if (!is_numeric($maxBytes) || (int) $maxBytes <= 0) {
            $errors[] = 'media.max_bytes invalid';
        }

        $allowed = $media['allowed_mime'] ?? null;
        if (!is_array($allowed) || $allowed === []) {
            $errors[] = 'media.allowed_mime invalid';
        }

        $byMime = $media['max_bytes_by_mime'] ?? null;
        if ($byMime !== null && !is_array($byMime)) {
            $errors[] = 'media.max_bytes_by_mime invalid';
        } elseif (is_array($byMime)) {
            foreach ($byMime as $mime => $limit) {
                if (!is_string($mime) || $mime === '' || !is_numeric($limit) || (int) $limit <= 0) {
                    $errors[] = 'media.max_bytes_by_mime value invalid';
                    break;
                }
            }
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkPerf(array $perf): array
    {
        $errors = [];
        $mode = strtolower((string) ($perf['guard_mode'] ?? ''));
        if ($mode !== '' && !in_array($mode, ['warn', 'block'], true)) {
            $errors[] = 'perf.guard_mode invalid';
        }

        $intKeys = [
            'db_max_queries',
            'db_max_unique',
            'db_max_total_ms',
            'http_max_calls',
            'http_max_total_ms',
            'total_max_ms',
            'db_max_queries_admin',
            'total_max_ms_admin',
            'total_ms_warn',
            'total_ms_hard',
            'sql_count_warn',
            'sql_count_hard',
            'sql_ms_warn',
            'sql_ms_hard',
        ];
        foreach ($intKeys as $key) {
            if (!array_key_exists($key, $perf)) {
                continue;
            }
            $value = $perf[$key];
            if (!is_numeric($value) || (int) $value < 0) {
                $errors[] = 'perf.' . $key . ' invalid';
            }
        }

        $paths = $perf['guard_exempt_paths'] ?? null;
        if ($paths !== null && !$this->isStringList($paths)) {
            $errors[] = 'perf.guard_exempt_paths invalid';
        }
        $routes = $perf['guard_exempt_routes'] ?? null;
        if ($routes !== null && !$this->isStringList($routes)) {
            $errors[] = 'perf.guard_exempt_routes invalid';
        }

        return $errors;
    }

    /** @return array<int, string> */
    private function checkCache(array $cache): array
    {
        $errors = [];
        $ttlKeys = [
            'ttl_default',
            'default_ttl',
            'ttl_settings',
            'ttl_permissions',
            'ttl_menus',
            'tag_ttl',
            'ttl_days',
        ];
        foreach ($ttlKeys as $key) {
            if (!array_key_exists($key, $cache)) {
                continue;
            }
            $value = $cache[$key];
            if (!is_numeric($value) || (int) $value < 0) {
                $errors[] = 'cache.' . $key . ' invalid';
            }
        }

        if (array_key_exists('devtools_tracking', $cache) && !is_bool($cache['devtools_tracking'])) {
            $errors[] = 'cache.devtools_tracking invalid';
        }

        return $errors;
    }

    private function isStringList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                return false;
            }
        }
        return true;
    }
}
