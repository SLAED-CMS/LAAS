<?php
declare(strict_types=1);

use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\LocalStorageDriver;
use Laas\Modules\Media\Service\MediaGcService;
use Laas\Modules\Media\Service\StorageWalker;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class MediaGcRetentionDryRunTest extends TestCase
{
    public function testRetentionDryRunDoesNotDelete(): void
    {
        $root = $this->createTempRoot();
        try {
            $db = $this->createDatabase();
            $pdo = $db->pdo();

            $oldDisk = 'uploads/2020/01/old.txt';
            $newDisk = 'uploads/2026/01/new.txt';
            $this->writeStorageFile($root, $oldDisk, 'old');
            $this->writeStorageFile($root, $newDisk, 'newer');

            $stmt = $pdo->prepare(
                'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, created_at, is_public)
                 VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :created_at, :is_public)'
            );
            $stmt->execute([
                'uuid' => 'u_old',
                'disk_path' => $oldDisk,
                'original_name' => 'old.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 3,
                'created_at' => '2020-01-01 00:00:00',
                'is_public' => 0,
            ]);
            $stmt->execute([
                'uuid' => 'u_new',
                'disk_path' => $newDisk,
                'original_name' => 'new.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 5,
                'created_at' => '2026-01-01 00:00:00',
                'is_public' => 0,
            ]);

            $repo = new MediaRepository($db);
            $driver = new LocalStorageDriver($root);
            $walker = new StorageWalker($root, $driver);
            $service = new MediaGcService($repo, $driver, $walker, [
                'gc_retention_days' => 30,
                'gc_exempt_prefixes' => [],
            ]);

            $result = $service->run([
                'mode' => 'retention',
                'dry_run' => true,
                'limit' => 10,
                'scan_prefix' => 'uploads/',
                'disk' => 'local',
            ]);

            $this->assertTrue($result['ok']);
            $this->assertSame(0, $result['deleted_count']);
            $this->assertGreaterThanOrEqual(1, $result['scanned_db']);
            $this->assertGreaterThan(0, $result['bytes_freed_estimate']);

            $this->assertFileExists($root . '/storage/' . $oldDisk);
            $this->assertFileExists($root . '/storage/' . $newDisk);
            $count = (int) $pdo->query('SELECT COUNT(*) FROM media_files')->fetchColumn();
            $this->assertSame(2, $count);
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
