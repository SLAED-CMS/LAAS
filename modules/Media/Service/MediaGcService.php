<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Support\AuditLogger;
use Throwable;

final class MediaGcService
{
    private MediaRepository $repo;
    private StorageDriverInterface $driver;
    private StorageWalker $walker;
    private array $config;
    private ?AuditLogger $audit;
    /** @var array<int, string> */
    private array $exemptPrefixes;
    private bool $allowDeletePublic;

    public function __construct(
        MediaRepository $repo,
        StorageDriverInterface $driver,
        StorageWalker $walker,
        array $config = [],
        ?AuditLogger $audit = null
    ) {
        $this->repo = $repo;
        $this->driver = $driver;
        $this->walker = $walker;
        $this->config = $config;
        $this->audit = $audit;
        $this->exemptPrefixes = $this->normalizePrefixes($config['gc_exempt_prefixes'] ?? []);
        $this->allowDeletePublic = (bool) ($config['gc_allow_delete_public'] ?? false);
    }

    /** @return array<string, mixed> */
    public function run(array $options): array
    {
        $mode = (string) ($options['mode'] ?? 'all');
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $limit = (int) ($options['limit'] ?? 0);
        $scanPrefix = (string) ($options['scan_prefix'] ?? 'uploads/');
        $disk = (string) ($options['disk'] ?? $this->driver->name());

        $result = [
            'ok' => true,
            'error' => null,
            'scanned_db' => 0,
            'scanned_storage' => 0,
            'orphans_found' => 0,
            'deleted_count' => 0,
            'bytes_freed_estimate' => 0,
            'disk' => $disk,
            'dry_run' => $dryRun,
            'mode' => $mode,
            'limit' => $limit,
        ];

        if (!in_array($mode, ['orphans', 'retention', 'all'], true)) {
            return $this->fail($result, 'invalid_mode');
        }

        if ($mode === 'orphans' || $mode === 'all') {
            $ok = $this->runOrphans($result, $scanPrefix, $dryRun, $limit);
            if (!$ok) {
                return $result;
            }
        }

        if ($mode === 'retention' || $mode === 'all') {
            $ok = $this->runRetention($result, $dryRun, $limit);
            if (!$ok) {
                return $result;
            }
        }

        $this->audit($result);

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function runOrphans(array &$result, string $scanPrefix, bool $dryRun, int $limit): bool
    {
        try {
            foreach ($this->walker->iterate($scanPrefix) as $item) {
                $result['scanned_storage']++;
                $diskPath = (string) ($item['disk_path'] ?? '');
                if ($diskPath === '' || $this->isExempt($diskPath)) {
                    continue;
                }

                if ($this->repo->existsByObjectKey($diskPath)) {
                    continue;
                }

                $result['orphans_found']++;
                $size = (int) ($item['size'] ?? 0);

                if ($dryRun) {
                    $result['bytes_freed_estimate'] += $size;
                    continue;
                }

                if ($limit > 0 && $result['deleted_count'] >= $limit) {
                    break;
                }

                $result['bytes_freed_estimate'] += $size;
                if (!$this->deleteObject($diskPath)) {
                    return $this->fail($result, 'storage_delete_failed');
                }

                $result['deleted_count']++;
            }
        } catch (Throwable) {
            return $this->fail($result, 'storage_list_failed');
        }

        return true;
    }

    /** @param array<string, mixed> $result */
    private function runRetention(array &$result, bool $dryRun, int $limit): bool
    {
        $days = (int) ($this->config['gc_retention_days'] ?? 0);
        if ($days <= 0) {
            return true;
        }

        $cutoff = $this->retentionCutoff($days);
        $afterId = 0;
        $batch = 100;

        while (true) {
            $rows = $this->repo->listCandidatesForRetention($cutoff, $batch, $afterId, $this->allowDeletePublic);
            if ($rows === []) {
                break;
            }

            $result['scanned_db'] += count($rows);
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > $afterId) {
                    $afterId = $id;
                }

                $diskPath = (string) ($row['disk_path'] ?? '');
                if ($diskPath === '' || $this->isExempt($diskPath)) {
                    continue;
                }

                $size = (int) ($row['size_bytes'] ?? 0);

                if ($dryRun) {
                    $result['bytes_freed_estimate'] += $size;
                    continue;
                }

                if ($limit > 0 && $result['deleted_count'] >= $limit) {
                    break 2;
                }

                $result['bytes_freed_estimate'] += $size;
                if (!$this->deleteObject($diskPath)) {
                    return $this->fail($result, 'storage_delete_failed');
                }

                if ($id > 0) {
                    $this->repo->delete($id);
                }
                $result['deleted_count']++;
            }
        }

        return true;
    }

    private function deleteObject(string $diskPath): bool
    {
        if ($diskPath === '') {
            return false;
        }

        try {
            return $this->driver->delete($diskPath);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $result */
    private function audit(array $result): void
    {
        if ($this->audit === null) {
            return;
        }

        $context = [
            'mode' => (string) ($result['mode'] ?? ''),
            'disk' => (string) ($result['disk'] ?? ''),
            'dry_run' => (bool) ($result['dry_run'] ?? true),
            'limit' => (int) ($result['limit'] ?? 0),
            'scanned_db' => (int) ($result['scanned_db'] ?? 0),
            'scanned_storage' => (int) ($result['scanned_storage'] ?? 0),
            'orphans_found' => (int) ($result['orphans_found'] ?? 0),
            'deleted_count' => (int) ($result['deleted_count'] ?? 0),
        ];

        if ($context['dry_run']) {
            $this->audit->log('media.gc.dry_run', 'media', null, $context);
            return;
        }

        if ($context['deleted_count'] > 0) {
            $this->audit->log('media.gc.delete', 'media', null, $context);
        }
    }

    /** @param array<string, mixed> $result */
    private function fail(array &$result, string $error): bool
    {
        $result['ok'] = false;
        $result['error'] = $error;
        return false;
    }

    private function retentionCutoff(int $days): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->sub(new DateInterval('P' . $days . 'D'));
        return $cutoff->format('Y-m-d H:i:s');
    }

    private function isExempt(string $diskPath): bool
    {
        $path = ltrim(str_replace('\\', '/', $diskPath), '/');
        $relative = $path;
        if (str_starts_with($relative, 'uploads/')) {
            $relative = substr($relative, strlen('uploads/'));
        }

        foreach ($this->exemptPrefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if (str_starts_with($path, $prefix) || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function normalizePrefixes(mixed $prefixes): array
    {
        if (!is_array($prefixes)) {
            return [];
        }

        $result = [];
        foreach ($prefixes as $prefix) {
            if (!is_string($prefix)) {
                continue;
            }
            $prefix = trim(str_replace('\\', '/', $prefix));
            if ($prefix === '') {
                continue;
            }
            $prefix = ltrim($prefix, '/');
            if (!str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }
            $result[] = $prefix;
        }

        return array_values(array_unique($result));
    }
}
