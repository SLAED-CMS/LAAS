<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Modules\Media\Repository\MediaRepository;
use Throwable;

final class MediaUploadReaper
{
    public function __construct(
        private MediaRepository $repository,
        private StorageService $storage
    ) {
    }

    /** @return array{ok: bool, scanned: int, deleted: int, quarantine_deleted: int, disk_deleted: int, cutoff: string, limit: int, errors: int} */
    public function reap(int $olderThanSeconds, int $limit = 0): array
    {
        $seconds = max(0, $olderThanSeconds);
        $limit = max(0, $limit);
        $cutoff = date('Y-m-d H:i:s', time() - $seconds);

        $rows = $this->repository->listUploadingOlderThan($cutoff, $limit);
        $scanned = count($rows);
        $deleted = 0;
        $quarantineDeleted = 0;
        $diskDeleted = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $quarantinePath = (string) ($row['quarantine_path'] ?? '');
            if ($quarantinePath !== '') {
                $absolute = $this->storage->absolutePath($quarantinePath);
                $quarantineDeleted += $this->deleteAbsolute($absolute) ? 1 : 0;
            }

            $diskPath = (string) ($row['disk_path'] ?? '');
            if ($diskPath !== '') {
                try {
                    $this->storage->delete($diskPath);
                    $diskDeleted++;
                } catch (Throwable) {
                    $errors++;
                }
            }

            if ($id > 0) {
                $this->repository->delete($id);
                $deleted++;
            }
        }

        return [
            'ok' => $errors === 0,
            'scanned' => $scanned,
            'deleted' => $deleted,
            'quarantine_deleted' => $quarantineDeleted,
            'disk_deleted' => $diskDeleted,
            'cutoff' => $cutoff,
            'limit' => $limit,
            'errors' => $errors,
        ];
    }

    private function deleteAbsolute(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $this->storage->deleteAbsolute($path);
        return !is_file($path);
    }
}
