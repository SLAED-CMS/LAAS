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
    use Laas\Modules\Media\Service\ImageDecoderInterface;
    use Laas\Modules\Media\Service\MediaThumbnailService;
    use Laas\Modules\Media\Service\MediaUploadService;
    use Laas\Modules\Media\Service\MimeSniffer;
    use Laas\Modules\Media\Service\StorageDriverInterface;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\TestCase;

    final class MediaStorageSelectionTest extends TestCase
    {
        public function testMediaUploadUsesS3Driver(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $driver = new FakeMemoryDriver('s3');
            $storage = new StorageService(dirname(__DIR__), $driver);

            $tmp = tempnam(sys_get_temp_dir(), 'laas_upload_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            $result = (new MediaUploadService(
                $repo,
                $storage,
                new MimeSniffer()
            ))->upload([
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ], 'pixel.png', [
                'allowed_mime' => ['image/png'],
                'max_bytes' => 1024 * 1024,
            ], 1);

            $this->assertSame('stored', $result['status'] ?? '');
            $this->assertNotSame('', $driver->lastPutDiskPath);
            $this->assertStringStartsWith('uploads/', $driver->lastPutDiskPath);
        }

        public function testThumbsSavedOnSelectedDiskS3(): void
        {
            $driver = new FakeMemoryDriver('s3');
            $storage = new StorageService(dirname(__DIR__), $driver);
            $decoder = new FakeThumbDecoder();

            $sourceDisk = 'uploads/2026/01/source.jpg';
            $driver->objects[$sourceDisk] = 'source';

            $media = [
                'mime_type' => 'image/jpeg',
                'disk_path' => $sourceDisk,
                'sha256' => 'sha256thumb',
            ];

            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_quality' => 82,
                'thumb_algo_version' => 1,
            ];

            $service = new MediaThumbnailService($storage, $decoder);
            $result = $service->sync($media, $config);

            $this->assertSame(1, $result['generated']);
            $this->assertNotSame('', $driver->lastPutDiskPath);
            $this->assertStringContainsString('/_cache/', $driver->lastPutDiskPath);
            $this->assertArrayHasKey($driver->lastPutDiskPath, $driver->objects);
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

    final class FakeMemoryDriver implements StorageDriverInterface
    {
        public array $objects = [];
        public string $lastPutDiskPath = '';
        private string $name;

        public function __construct(string $name)
        {
            $this->name = $name;
        }

        public function name(): string
        {
            return $this->name;
        }

        public function put(string $diskPath, string $sourcePath): bool
        {
            if (!is_file($sourcePath)) {
                return false;
            }
            $contents = file_get_contents($sourcePath);
            if ($contents === false) {
                return false;
            }
            $this->lastPutDiskPath = $diskPath;
            $this->objects[$diskPath] = $contents;
            return true;
        }

        public function putContents(string $diskPath, string $contents): bool
        {
            $this->lastPutDiskPath = $diskPath;
            $this->objects[$diskPath] = $contents;
            return true;
        }

        public function getStream(string $diskPath)
        {
            if (!isset($this->objects[$diskPath])) {
                return false;
            }
            $stream = fopen('php://temp', 'wb+');
            if ($stream === false) {
                return false;
            }
            fwrite($stream, (string) $this->objects[$diskPath]);
            rewind($stream);
            return $stream;
        }

        public function exists(string $diskPath): bool
        {
            return array_key_exists($diskPath, $this->objects);
        }

        public function delete(string $diskPath): bool
        {
            unset($this->objects[$diskPath]);
            return true;
        }

        public function size(string $diskPath): int
        {
            if (!isset($this->objects[$diskPath])) {
                return 0;
            }
            return strlen((string) $this->objects[$diskPath]);
        }

        public function stats(): array
        {
            return ['requests' => 0, 'total_ms' => 0.0];
        }
    }

    final class FakeThumbDecoder implements ImageDecoderInterface
    {
        public function supportsMime(string $mime): bool
        {
            return true;
        }

        public function getWidth(string $sourcePath): ?int
        {
            return 200;
        }

        public function getHeight(string $sourcePath): ?int
        {
            return 100;
        }

        public function stripMetadata(string $path): bool
        {
            return true;
        }

        public function createThumbnail(
            string $sourcePath,
            string $targetPath,
            int $maxWidth,
            string $format,
            int $quality,
            int $maxPixels,
            float $deadline
        ): bool {
            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
            return file_put_contents($targetPath, 'thumb') !== false;
        }
    }
}
