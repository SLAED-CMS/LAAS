<?php
declare(strict_types=1);

use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\LocalStorageDriver;
use Laas\Modules\Media\Service\MediaGcService;
use Laas\Modules\Media\Service\StorageWalker;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class MediaGcLimitRespectedTest extends TestCase
{
    public function testLimitStopsDeletion(): void
    {
        $root = $this->createTempRoot();
        try {
            $db = $this->createDatabase();

            $orphan1 = 'uploads/2026/01/orphan1.txt';
            $orphan2 = 'uploads/2026/01/orphan2.txt';
            $orphan3 = 'uploads/2026/01/orphan3.txt';
            $this->writeStorageFile($root, $orphan1, 'a');
            $this->writeStorageFile($root, $orphan2, 'b');
            $this->writeStorageFile($root, $orphan3, 'c');

            $repo = new MediaRepository($db);
            $driver = new LocalStorageDriver($root);
            $walker = new StorageWalker($root, $driver);
            $service = new MediaGcService($repo, $driver, $walker, [
                'gc_retention_days' => 180,
                'gc_exempt_prefixes' => [],
            ]);

            $result = $service->run([
                'mode' => 'orphans',
                'dry_run' => false,
                'limit' => 1,
                'scan_prefix' => 'uploads/',
                'disk' => 'local',
            ]);

            $this->assertTrue($result['ok']);
            $this->assertSame(1, $result['deleted_count']);

            $remaining = 0;
            foreach ([$orphan1, $orphan2, $orphan3] as $path) {
                if (is_file($root . '/storage/' . $path)) {
                    $remaining++;
                }
            }
            $this->assertSame(2, $remaining);
        } finally {
            $this->cleanupRoot($root);
        }
    }

    private function createTempRoot(): string
    {
        $root = sys_get_temp_dir() . '/laas_media_gc_' . bin2hex(random_bytes(4));
        mkdir($root, 0775, true);
        return $root;
    }

    private function createDatabase(): Laas\Database\DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedMediaTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function writeStorageFile(string $root, string $diskPath, string $contents): void
    {
        $path = $root . '/storage/' . $diskPath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
    }

    private function cleanupRoot(string $root): void
    {
        if ($root === '' || !is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }
}
