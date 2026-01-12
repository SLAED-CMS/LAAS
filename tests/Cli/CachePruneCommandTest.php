<?php
declare(strict_types=1);

use Laas\Support\Cache\CachePruner;
use PHPUnit\Framework\TestCase;

final class CachePruneCommandTest extends TestCase
{
    public function testPruneRemovesOldFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $cacheRoot = $root . '/storage/cache/test_prune';
        if (!is_dir($cacheRoot)) {
            mkdir($cacheRoot, 0775, true);
        }

        $oldFile = $cacheRoot . '/old.txt';
        $newFile = $cacheRoot . '/new.txt';
        file_put_contents($oldFile, 'old');
        file_put_contents($newFile, 'new');

        $oldTime = time() - (9 * 86400);
        touch($oldFile, $oldTime);

        $pruner = new CachePruner($cacheRoot);
        $result = $pruner->prune(7);

        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
        $this->assertGreaterThanOrEqual(1, (int) ($result['scanned'] ?? 0));

        @unlink($newFile);
        @rmdir($cacheRoot);
    }
}

