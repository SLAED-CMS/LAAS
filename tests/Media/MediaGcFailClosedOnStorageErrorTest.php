<?php
declare(strict_types=1);

use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\LocalStorageDriver;
use Laas\Modules\Media\Service\MediaGcService;
use Laas\Modules\Media\Service\StorageWalker;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class MediaGcFailClosedOnStorageErrorTest extends TestCase
{
    public function testFailsClosedOnStorageError(): void
    {
        $root = $this->createTempRoot();
        try {
            $db = $this->createDatabase();
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
                'limit' => 10,
                'scan_prefix' => 'uploads/',
                'disk' => 'local',
            ]);

            $this->assertFalse($result['ok']);
            $this->assertSame('storage_list_failed', $result['error']);
            $this->assertSame(0, $result['deleted_count']);
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
