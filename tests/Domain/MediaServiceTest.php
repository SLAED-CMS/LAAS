<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Media\MediaServiceException;
use Laas\Modules\Media\Service\StorageService;
use PHPUnit\Framework\TestCase;

final class DomainMediaServiceTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_media_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath . '/storage');
        if (is_dir($this->rootPath)) {
            @rmdir($this->rootPath);
        }
    }

    public function testValidUploadStoresMedia(): void
    {
        $db = $this->createDb();
        $config = $this->loadConfig();
        $service = new MediaService($db, $config, $this->rootPath);

        $tmp = $this->createTempPng();
        $file = [
            'name' => 'pixel.png',
            'tmp_path' => $tmp,
            'size' => (int) (filesize($tmp) ?: 0),
            'mime' => 'image/png',
        ];

        $media = $service->upload($file, ['user_id' => 1]);

        $this->assertNotEmpty($media['id'] ?? null);
        $this->assertSame('image/png', $media['mime_type'] ?? null);

        $storage = new StorageService($this->rootPath);
        $diskPath = (string) ($media['disk_path'] ?? '');
        $this->assertTrue($diskPath !== '' && is_file($storage->absolutePath($diskPath)));
        $storage->delete($diskPath);
    }

    public function testInvalidMimeThrows(): void
    {
        $db = $this->createDb();
        $config = $this->loadConfig();
        $service = new MediaService($db, $config, $this->rootPath);

        $tmp = $this->createTempText();
        $file = [
            'name' => 'note.txt',
            'tmp_path' => $tmp,
            'size' => (int) (filesize($tmp) ?: 0),
            'mime' => 'text/plain',
        ];

        $this->expectException(MediaServiceException::class);
        $service->upload($file);
    }

    public function testOversizeThrows(): void
    {
        $db = $this->createDb();
        $config = $this->loadConfig();
        $config['max_bytes'] = 1;
        $service = new MediaService($db, $config, $this->rootPath);

        $tmp = $this->createTempPng();
        $file = [
            'name' => 'big.png',
            'tmp_path' => $tmp,
            'size' => (int) (filesize($tmp) ?: 0),
            'mime' => 'image/png',
        ];

        $this->expectException(MediaServiceException::class);
        $service->upload($file);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->pdo()->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'ready\',
            quarantine_path TEXT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL
        )');
        $db->pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_media_files_sha256 ON media_files (sha256)');

        return $db;
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/media.php';
        $config = is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    private function createTempPng(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_media_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }
        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true);
        if ($data === false) {
            $this->fail('Failed to decode PNG data');
        }
        file_put_contents($tmp, $data);
        return $tmp;
    }

    private function createTempText(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_media_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }
        file_put_contents($tmp, 'not an image');
        return $tmp;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($path);
    }
}
