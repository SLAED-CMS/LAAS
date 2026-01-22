<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class LocalStorageDriver implements StorageDriverInterface
{
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/\\');
    }

    public function name(): string
    {
        return 'local';
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        $target = $this->absolutePath($diskPath);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return copy($sourcePath, $target);
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        $target = $this->absolutePath($diskPath);
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($target, $contents, LOCK_EX) !== false;
    }

    public function getStream(string $diskPath)
    {
        $path = $this->absolutePath($diskPath);
        if (!is_file($path)) {
            return false;
        }

        return fopen($path, 'rb');
    }

    public function exists(string $diskPath): bool
    {
        return is_file($this->absolutePath($diskPath));
    }

    public function delete(string $diskPath): bool
    {
        $path = $this->absolutePath($diskPath);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function size(string $diskPath): int
    {
        $path = $this->absolutePath($diskPath);
        if (!is_file($path)) {
            return 0;
        }

        $size = filesize($path);
        return $size === false ? 0 : (int) $size;
    }

    public function stats(): array
    {
        return ['requests' => 0, 'total_ms' => 0.0];
    }

    private function absolutePath(string $diskPath): string
    {
        return $this->rootPath . '/storage/' . ltrim($diskPath, '/');
    }
}
