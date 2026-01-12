<?php
declare(strict_types=1);

namespace Laas\Support\Cache;

final class CachePruner
{
    public function __construct(private string $cacheRoot)
    {
    }

    /**
     * @return array{deleted: int, scanned: int}
     */
    public function prune(int $ttlDays): array
    {
        $deleted = 0;
        $scanned = 0;
        $root = rtrim($this->cacheRoot, '/\\');
        if (!is_dir($root)) {
            return ['deleted' => 0, 'scanned' => 0];
        }

        $cutoff = time() - max(0, $ttlDays) * 86400;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $scanned++;
            $path = $file->getPathname();
            $mtime = $file->getMTime();
            if ($mtime >= $cutoff) {
                continue;
            }
            if (@unlink($path)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted, 'scanned' => $scanned];
    }
}
