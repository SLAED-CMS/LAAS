<?php
declare(strict_types=1);

use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\LocalStorageDriver;
use Laas\Modules\Media\Service\MediaVerifyService;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class MediaVerifyMissingTest extends TestCase
{
    public function testDetectsMissingObjects(): void
    {
        $root = $this->createTempRoot();
        try {
            $db = $this->createDatabase();
            $pdo = $db->pdo();

            $okDisk = 'uploads/2026/01/ok.txt';
            $missingDisk = 'uploads/2026/01/missing.txt';
            $this->writeStorageFile($root, $okDisk, 'hello');

            $stmt = $pdo->prepare(
                'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, created_at, is_public)
                 VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :created_at, :is_public)'
            );
            $stmt->execute([
                'uuid' => 'u_ok',
                'disk_path' => $okDisk,
                'original_name' => 'ok.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 5,
                'created_at' => '2026-01-01 00:00:00',
                'is_public' => 0,
            ]);
            $stmt->execute([
                'uuid' => 'u_missing',
                'disk_path' => $missingDisk,
                'original_name' => 'missing.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 7,
                'created_at' => '2026-01-01 00:00:00',
                'is_public' => 0,
            ]);

            $repo = new MediaRepository($db);
            $driver = new LocalStorageDriver($root);
            $service = new MediaVerifyService($root, $repo, $driver);
            $result = $service->verify(10);

            $this->assertSame(1, $result['ok_count']);
            $this->assertSame(1, $result['missing_count']);
            $this->assertSame(0, $result['mismatch_count']);
        } finally {
            $this->cleanupRoot($root);
        }
    }

    private function createTempRoot(): string
    {
        $root = sys_get_temp_dir() . '/laas_media_verify_' . bin2hex(random_bytes(4));
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
