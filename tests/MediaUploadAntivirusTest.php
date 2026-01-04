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
    use Laas\Modules\Media\Service\AntivirusScannerInterface;
    use Laas\Modules\Media\Service\MediaUploadService;
    use Laas\Modules\Media\Service\MimeSniffer;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;

    #[Group('security')]
    final class MediaUploadAntivirusTest extends TestCase
    {
        private string $rootPath;

        protected function setUp(): void
        {
            $this->rootPath = dirname(__DIR__);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_unset();
            }
            $_FILES = [];
        }

        public function testClamAvDisabledUploadOk(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->rootPath);
            $scanner = new FakeScanner(['status' => 'error']);

            $tmp = tempnam(sys_get_temp_dir(), 'laas_av_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            $file = [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];

            $config = [
                'max_bytes' => 10 * 1024 * 1024,
                'allowed_mime' => ['image/png'],
                'max_bytes_by_mime' => [],
                'av_enabled' => false,
            ];

            $service = new MediaUploadService($repo, $storage, new MimeSniffer(), $scanner);
            $result = $service->upload($file, 'pixel.png', $config, 1);

            $this->assertSame('stored', $result['status'] ?? null);
            $this->assertSame(0, $scanner->calls);

            $row = $repo->findById((int) ($result['id'] ?? 0));
            if ($row !== null) {
                $storage->delete((string) ($row['disk_path'] ?? ''));
            }
        }

        public function testClamAvEnabledScanErrorRejects(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->rootPath);
            $scanner = new FakeScanner(['status' => 'error']);

            $tmp = tempnam(sys_get_temp_dir(), 'laas_av_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            $file = [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];

            $config = [
                'max_bytes' => 10 * 1024 * 1024,
                'allowed_mime' => ['image/png'],
                'max_bytes_by_mime' => [],
                'av_enabled' => true,
            ];

            $service = new MediaUploadService($repo, $storage, new MimeSniffer(), $scanner);
            $result = $service->upload($file, 'pixel.png', $config, 1);

            $this->assertSame('error', $result['status'] ?? null);
            $this->assertSame('media.upload_virus_detected', $result['errors'][0]['key'] ?? null);
            $this->assertSame([], $repo->list(10, 0, ''));
        }

        public function testPerMimeSizeLimitEnforced(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->rootPath);

            $tmp = tempnam(sys_get_temp_dir(), 'laas_av_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            $file = [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];

            $config = [
                'max_bytes' => 10 * 1024 * 1024,
                'allowed_mime' => ['image/png'],
                'max_bytes_by_mime' => ['image/png' => max(1, $size - 1)],
                'av_enabled' => false,
            ];

            $service = new MediaUploadService($repo, $storage, new MimeSniffer());
            $result = $service->upload($file, 'pixel.png', $config, 1);

            $this->assertSame('error', $result['status'] ?? null);
            $this->assertSame('media.upload_mime_too_large', $result['errors'][0]['key'] ?? null);
            $this->assertSame([], $repo->list(10, 0, ''));
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            $pdo->exec('CREATE TABLE media_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL,
                disk_path TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 TEXT NULL,
                uploaded_by INTEGER NULL,
                created_at TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL
        )');

            $db = new DatabaseManager(['driver' => 'mysql']);
            $ref = new \ReflectionProperty($db, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($db, $pdo);

            return $db;
        }
    }

    final class FakeScanner implements AntivirusScannerInterface
    {
        public int $calls = 0;

        public function __construct(private array $result)
        {
        }

        public function scan(string $path): array
        {
            $this->calls++;
            return $this->result;
        }
    }
}
