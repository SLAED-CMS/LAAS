<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use DateTimeImmutable;
use RuntimeException;

final class StorageService
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return array{uuid: string, disk_path: string, absolute_path: string} */
    public function storeUploadedFile(array $file, string $extension): array
    {
        $quarantine = $this->storeUploadedToQuarantine($file);
        return $this->finalizeFromQuarantine($quarantine['absolute_path'], $extension);
    }

    public function absolutePath(string $diskPath): string
    {
        return $this->rootPath . '/storage/' . ltrim($diskPath, '/');
    }

    /** @return array{disk_path: string, absolute_path: string} */
    public function storeUploadedToQuarantine(array $file): array
    {
        $name = bin2hex(random_bytes(16)) . '.tmp';
        $diskPath = 'uploads/quarantine/' . $name;
        $absolutePath = $this->absolutePath($diskPath);

        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create upload directory');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded file');
        }

        if (!move_uploaded_file($tmp, $absolutePath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        return [
            'disk_path' => $diskPath,
            'absolute_path' => $absolutePath,
        ];
    }

    /** @return array{uuid: string, disk_path: string, absolute_path: string} */
    public function finalizeFromQuarantine(string $quarantinePath, string $extension, ?DateTimeImmutable $now = null): array
    {
        $uuid = $this->randomId();
        $diskPath = $this->buildDiskPath($now ?? new DateTimeImmutable(), $uuid, $extension);
        $absolutePath = $this->absolutePath($diskPath);

        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create upload directory');
        }

        if (!rename($quarantinePath, $absolutePath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        return [
            'uuid' => $uuid,
            'disk_path' => $diskPath,
            'absolute_path' => $absolutePath,
        ];
    }

    public function delete(string $diskPath): void
    {
        $path = $this->absolutePath($diskPath);
        $this->deleteAbsolute($path);
    }

    public function deleteAbsolute(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function buildDiskPath(DateTimeImmutable $now, string $uuid, string $extension): string
    {
        $year = $now->format('Y');
        $month = $now->format('m');
        $ext = ltrim($extension, '.');
        return sprintf('uploads/%s/%s/%s.%s', $year, $month, $uuid, $ext);
    }

    private function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
