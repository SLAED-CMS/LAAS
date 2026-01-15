<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service {
    if (!function_exists(__NAMESPACE__ . '\\is_uploaded_file')) {
        function is_uploaded_file(string $filename): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            return rename($from, $to);
        }
    }
}

namespace {
    use Laas\Database\DatabaseManager;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\MediaUploadService;
    use Laas\Modules\Media\Service\MimeSniffer;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\TestCase;

    final class MediaUploadDedupeRaceTest extends TestCase
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

        public function testDuplicateUploadReturnsExistingAndCleansQuarantine(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->rootPath);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $config = [
                'max_bytes' => 1024 * 1024,
                'allowed_mime' => ['image/png'],
                'max_bytes_by_mime' => [],
                'av_enabled' => false,
            ];

            $upload1 = $this->createTempUpload();
            $result1 = $service->upload($upload1, 'pixel.png', $config, 1);
            $this->assertSame('stored', $result1['status'] ?? null);
            $id1 = (int) ($result1['id'] ?? 0);
            $this->assertGreaterThan(0, $id1);

            $upload2 = $this->createTempUpload();
            $result2 = $service->upload($upload2, 'pixel.png', $config, 1);
            $this->assertSame('deduped', $result2['status'] ?? null);
            $this->assertSame($id1, (int) ($result2['id'] ?? 0));

            $rows = $repo->list(10, 0, '');
            $this->assertCount(1, $rows);
            $diskPath = (string) ($rows[0]['disk_path'] ?? '');
            $finalPath = $diskPath !== '' ? $storage->absolutePath($diskPath) : '';
            $this->assertNotSame('', $finalPath);
            $this->assertTrue(is_file($finalPath));

            $quarantineDir = $this->rootPath . '/storage/uploads/quarantine';
            $remaining = is_dir($quarantineDir) ? glob($quarantineDir . '/*.tmp') : [];
            $this->assertSame([], $remaining);

            if ($diskPath !== '') {
                $storage->delete($diskPath);
            }
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

        /** @return array<string, mixed> */
        private function createTempUpload(): array
        {
            $tmp = tempnam(sys_get_temp_dir(), 'laas_upload_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            return [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];
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
