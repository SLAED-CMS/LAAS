<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class StorageWalker
{
    private string $rootPath;
    private StorageDriverInterface $driver;

    public function __construct(string $rootPath, StorageDriverInterface $driver)
    {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->driver = $driver;
    }

    /** @return iterable<int, array{disk_path: string, size: int}> */
    public function iterate(string $prefix): iterable
    {
        if ($this->driver instanceof S3Storage) {
            yield from $this->iterateS3($prefix, $this->driver);
            return;
        }

        yield from $this->iterateLocal($prefix);
    }

    /** @return iterable<int, array{disk_path: string, size: int}> */
    private function iterateLocal(string $prefix): iterable
    {
        $storageRoot = $this->rootPath . '/storage';
        $prefix = trim($prefix, '/');
        $base = $prefix === '' ? $storageRoot : $storageRoot . '/' . $prefix;
        if (!is_dir($base)) {
            throw new RuntimeException('storage_list_failed');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        $baseLen = strlen($storageRoot);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, $baseLen + 1);
            if ($relative === false) {
                continue;
            }
            $relative = str_replace('\\', '/', $relative);

            yield [
                'disk_path' => $relative,
                'size' => (int) $file->getSize(),
            ];
        }
    }

    /** @return iterable<int, array{disk_path: string, size: int}> */
    private function iterateS3(string $prefix, S3Storage $driver): iterable
    {
        $token = null;
        $prefix = trim($prefix, '/') . '/';
        if ($prefix === '/') {
            $prefix = '';
        }

        do {
            $result = $driver->listObjects($prefix, $token, 1000);
            $items = $result['items'] ?? [];
            foreach ($items as $item) {
                $diskPath = (string) ($item['disk_path'] ?? '');
                if ($diskPath === '' || str_ends_with($diskPath, '/')) {
                    continue;
                }

                yield [
                    'disk_path' => $diskPath,
                    'size' => (int) ($item['size'] ?? 0),
                ];
            }
            $token = $result['next_token'] ?? null;
        } while ($token !== null && $token !== '');
    }
}
