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
        $uuid = $this->uuidV4();
        $diskPath = $this->buildDiskPath(new DateTimeImmutable(), $uuid, $extension);
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
            'uuid' => $uuid,
            'disk_path' => $diskPath,
            'absolute_path' => $absolutePath,
        ];
    }

    public function absolutePath(string $diskPath): string
    {
        return $this->rootPath . '/storage/' . ltrim($diskPath, '/');
    }

    public function delete(string $diskPath): void
    {
        $path = $this->absolutePath($diskPath);
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

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
