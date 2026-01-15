<?php
declare(strict_types=1);

namespace {
    use Laas\Database\DatabaseManager;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\MediaUploadReaper;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\TestCase;

    final class MediaUploadReaperTest extends TestCase
    {
        private string $rootPath;

        protected function setUp(): void
        {
            $this->rootPath = $this->createTempRoot();
        }

        protected function tearDown(): void
        {
            $this->removeDir($this->rootPath);
        }

        public function testReaperDeletesStaleUploadingRows(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->rootPath);
            $reaper = new MediaUploadReaper($repo, $storage);

            $diskPath = 'uploads/2026/01/reap.jpg';
            $quarantinePath = 'uploads/quarantine/reap.tmp';
            $diskAbs = $storage->absolutePath($diskPath);
            $quarantineAbs = $storage->absolutePath($quarantinePath);
            $this->writeFile($diskAbs, 'disk');
            $this->writeFile($quarantineAbs, 'quarantine');

            $createdAt = date('Y-m-d H:i:s', time() - 3600);
            $stmt = $db->pdo()->prepare(
                'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token, status, quarantine_path)
                 VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token, :status, :quarantine_path)'
            );
            $stmt->execute([
                'uuid' => 'u1',
                'disk_path' => $diskPath,
                'original_name' => 'reap.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 4,
                'sha256' => 'hash-reap',
                'uploaded_by' => null,
                'created_at' => $createdAt,
                'is_public' => 0,
                'public_token' => null,
                'status' => 'uploading',
                'quarantine_path' => $quarantinePath,
            ]);

            $result = $reaper->reap(60, 0);
            $this->assertSame(1, $result['scanned']);
            $this->assertSame(1, $result['deleted']);
            $this->assertSame(1, $result['quarantine_deleted']);
            $this->assertSame(1, $result['disk_deleted']);

            $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM media_files')->fetchColumn();
            $this->assertSame(0, $count);
            $this->assertFalse(is_file($quarantineAbs));
            $this->assertFalse(is_file($diskAbs));
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            $pdo->exec('CREATE TABLE media_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL,
                disk_path TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                uploaded_by INTEGER NULL,
                created_at TEXT NOT NULL,
                is_public INTEGER NOT NULL DEFAULT 0,
                public_token TEXT NULL,
                status TEXT NOT NULL,
                quarantine_path TEXT NULL
            )');
            $pdo->exec('CREATE UNIQUE INDEX idx_media_files_sha256 ON media_files(sha256)');

            $db = new DatabaseManager(['driver' => 'mysql']);
            $ref = new ReflectionProperty($db, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($db, $pdo);

            return $db;
        }

        private function writeFile(string $path, string $contents): void
        {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->fail('Failed to create storage directory.');
            }
            file_put_contents($path, $contents);
        }

        private function createTempRoot(): string
        {
            $root = sys_get_temp_dir() . '/laas_media_' . bin2hex(random_bytes(4));
            if (!mkdir($root, 0775, true) && !is_dir($root)) {
                $this->fail('Failed to create temp root.');
            }

            return $root;
        }

        private function removeDir(string $path): void
        {
            if ($path === '' || !is_dir($path)) {
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
            @rmdir($path);
        }
    }
}
