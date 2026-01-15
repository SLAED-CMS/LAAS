<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use DateTimeImmutable;
use RuntimeException;

final class StorageService
{
    private StorageDriverInterface $driver;
    private bool $misconfigured = false;

    public function __construct(private string $rootPath, ?StorageDriverInterface $driver = null)
    {
        $this->rootPath = rtrim($rootPath, '/\\');

        if ($driver !== null) {
            $this->driver = $driver;
            return;
        }

        $config = $this->storageConfig();
        $disk = (string) ($config['default'] ?? 'local');
        $disk = in_array($disk, ['local', 's3'], true) ? $disk : 'local';

        if ($disk === 's3') {
            $s3 = $config['disks']['s3'] ?? [];
            if (!$this->hasS3Config($s3)) {
                $this->misconfigured = true;
                $this->driver = new LocalStorageDriver($this->rootPath);
                return;
            }
            $this->driver = new S3Storage($s3);
            return;
        }

        $this->driver = new LocalStorageDriver($this->rootPath);
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
        $isUpload = $tmp !== '' && is_uploaded_file($tmp);
        if (!$isUpload && (!$this->allowLocalUpload() || !is_file($tmp))) {
            throw new RuntimeException('Invalid uploaded file');
        }

        if ($isUpload) {
            if (!move_uploaded_file($tmp, $absolutePath)) {
                throw new RuntimeException('Failed to move uploaded file');
            }
        } else {
            if (!copy($tmp, $absolutePath)) {
                throw new RuntimeException('Failed to move uploaded file');
            }
            @unlink($tmp);
        }

        return [
            'disk_path' => $diskPath,
            'absolute_path' => $absolutePath,
        ];
    }

    /** @return array{uuid: string, disk_path: string, absolute_path: string} */
    public function finalizeFromQuarantine(string $quarantinePath, string $extension, ?DateTimeImmutable $now = null): array
    {
        if ($this->misconfigured) {
            throw new RuntimeException('s3_misconfigured');
        }

        $uuid = $this->randomId();
        $diskPath = $this->buildDiskPath($now ?? new DateTimeImmutable(), $uuid, $extension);
        $absolutePath = $this->absolutePath($diskPath);

        if ($this->driver->name() === 'local') {
            $dir = dirname($absolutePath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create upload directory');
            }

            if (!rename($quarantinePath, $absolutePath)) {
                throw new RuntimeException('Failed to move uploaded file');
            }
        } else {
            if (!$this->driver->put($diskPath, $quarantinePath)) {
                throw new RuntimeException('s3_upload_failed');
            }
            $this->deleteAbsolute($quarantinePath);
        }

        return [
            'uuid' => $uuid,
            'disk_path' => $diskPath,
            'absolute_path' => $absolutePath,
        ];
    }

    public function moveQuarantineToDiskPath(string $quarantinePath, string $diskPath): string
    {
        if ($this->misconfigured) {
            throw new RuntimeException('s3_misconfigured');
        }

        $absolutePath = $this->absolutePath($diskPath);

        if ($this->driver->name() === 'local') {
            $dir = dirname($absolutePath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create upload directory');
            }

            if (!rename($quarantinePath, $absolutePath)) {
                throw new RuntimeException('Failed to move uploaded file');
            }

            return $absolutePath;
        }

        if (!$this->driver->put($diskPath, $quarantinePath)) {
            throw new RuntimeException('s3_upload_failed');
        }
        $this->deleteAbsolute($quarantinePath);

        return $absolutePath;
    }

    public function delete(string $diskPath): void
    {
        if ($this->misconfigured) {
            throw new RuntimeException('s3_misconfigured');
        }

        if ($this->driver->name() === 'local') {
            $path = $this->absolutePath($diskPath);
            $this->deleteAbsolute($path);
            return;
        }

        $this->driver->delete($diskPath);
    }

    public function deleteAbsolute(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        if ($this->misconfigured) {
            return false;
        }

        return $this->driver->put($diskPath, $sourcePath);
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        if ($this->misconfigured) {
            return false;
        }

        return $this->driver->putContents($diskPath, $contents);
    }

    /** @return resource|false */
    public function getStream(string $diskPath)
    {
        if ($this->misconfigured) {
            return false;
        }

        return $this->driver->getStream($diskPath);
    }

    public function exists(string $diskPath): bool
    {
        if ($this->misconfigured) {
            return false;
        }

        return $this->driver->exists($diskPath);
    }

    public function size(string $diskPath): int
    {
        if ($this->misconfigured) {
            return 0;
        }

        return $this->driver->size($diskPath);
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    /** @return array{requests: int, total_ms: float} */
    public function stats(): array
    {
        return $this->driver->stats();
    }

    public function isMisconfigured(): bool
    {
        return $this->misconfigured;
    }

    public function readToTemp(string $diskPath): ?string
    {
        if ($this->misconfigured) {
            return null;
        }

        if ($this->driver->name() === 'local') {
            $path = $this->absolutePath($diskPath);
            return is_file($path) ? $path : null;
        }

        $stream = $this->driver->getStream($diskPath);
        if ($stream === false) {
            return null;
        }

        $tmp = $this->tmpPath();
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            return null;
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $tmp;
    }

    public function getContents(string $diskPath): ?string
    {
        if ($this->misconfigured) {
            return null;
        }

        $stream = $this->driver->getStream($diskPath);
        if ($stream === false) {
            return null;
        }

        $data = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $data === false ? null : (string) $data;
    }

    private function tmpPath(): string
    {
        $dir = $this->rootPath . '/storage/tmp';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        return $dir . '/laas_' . bin2hex(random_bytes(8)) . '.tmp';
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

    private function storageConfig(): array
    {
        $path = $this->rootPath . '/config/storage.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function hasS3Config(array $config): bool
    {
        return ($config['region'] ?? '') !== ''
            && ($config['bucket'] ?? '') !== ''
            && ($config['access_key'] ?? '') !== ''
            && ($config['secret_key'] ?? '') !== '';
    }

    private function allowLocalUpload(): bool
    {
        return strtolower((string) getenv('APP_ENV')) === 'test';
    }
}
