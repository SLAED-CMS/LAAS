<?php
declare(strict_types=1);

namespace {
    use Laas\Database\DatabaseManager;
    use Laas\Http\Request;
    use Laas\Modules\Media\Controller\MediaThumbController;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\ImageDecoderInterface;
    use Laas\Modules\Media\Service\MediaThumbnailService;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\TestCase;

    final class MediaThumbnailServiceTest extends TestCase
    {
        private string $rootPath;
        private StorageService $storage;
        private FakeDecoder $decoder;

        protected function setUp(): void
        {
            $this->rootPath = sys_get_temp_dir() . '/laas_media_' . bin2hex(random_bytes(4));
            @mkdir($this->rootPath . '/storage/uploads/2026/01', 0775, true);
            $this->storage = new StorageService($this->rootPath);
            $this->decoder = new FakeDecoder();
        }

        protected function tearDown(): void
        {
            $this->removeDir($this->rootPath);
        }

        public function testThumbGenerationForJpegAndPng(): void
        {
            $jpeg = $this->createMedia('image/jpeg', 'uploads/2026/01/a.jpg', 'sha256jpeg');
            $png = $this->createMedia('image/png', 'uploads/2026/01/b.png', 'sha256png');

            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_quality' => 82,
                'thumb_algo_version' => 1,
            ];

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $resultJpeg = $service->sync($jpeg, $config);
            $resultPng = $service->sync($png, $config);

            $this->assertSame(1, $resultJpeg['generated']);
            $this->assertSame(1, $resultPng['generated']);

            $jpegThumb = $this->storage->absolutePath('uploads/_cache/2026/01/sha256jpeg/sm_v1.webp');
            $pngThumb = $this->storage->absolutePath('uploads/_cache/2026/01/sha256png/sm_v1.webp');
            $this->assertFileExists($jpegThumb);
            $this->assertFileExists($pngThumb);
        }

        public function testSkipNonImage(): void
        {
            $pdf = $this->createMedia('application/pdf', 'uploads/2026/01/c.pdf', 'sha256pdf');
            $config = ['thumb_variants' => ['sm' => 200]];

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $result = $service->sync($pdf, $config);

            $this->assertSame(0, $result['generated']);
            $this->assertSame(0, $this->decoder->calls);
        }

        public function testCacheReuseNoRegen(): void
        {
            $jpeg = $this->createMedia('image/jpeg', 'uploads/2026/01/d.jpg', 'sha256reuse');
            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_algo_version' => 1,
            ];

            $thumb = $this->storage->absolutePath('uploads/_cache/2026/01/sha256reuse/sm_v1.webp');
            @mkdir(dirname($thumb), 0775, true);
            file_put_contents($thumb, 'thumb');

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $result = $service->sync($jpeg, $config);

            $this->assertSame(1, $result['skipped']);
            $this->assertSame(0, $this->decoder->calls);
        }

        public function testRejectImageOverPixelLimit(): void
        {
            $jpeg = $this->createMedia('image/jpeg', 'uploads/2026/01/pixels.jpg', 'sha256pixels');
            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_algo_version' => 1,
                'image_max_pixels' => 10,
            ];

            $this->decoder->width = 5;
            $this->decoder->height = 3;

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $result = $service->sync($jpeg, $config);

            $this->assertSame(0, $result['generated']);
            $this->assertSame(1, $result['failed']);
            $this->assertSame(0, $this->decoder->calls);

            $reason = $this->storage->absolutePath('uploads/_cache/2026/01/sha256pixels/sm_v1.webp.reason');
            $this->assertFileExists($reason);
            $this->assertSame('too_many_pixels', trim((string) file_get_contents($reason)));
        }

        public function testMetadataStripEnforced(): void
        {
            $jpeg = $this->createMedia('image/jpeg', 'uploads/2026/01/meta.jpg', 'sha256meta');
            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_quality' => 82,
                'thumb_algo_version' => 1,
            ];

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $result = $service->sync($jpeg, $config);

            $this->assertSame(1, $result['generated']);
            $this->assertTrue($this->decoder->stripCalled);
        }

        public function testDeterministicOutput(): void
        {
            $jpeg = $this->createMedia('image/jpeg', 'uploads/2026/01/det.jpg', 'sha256det');
            $config = [
                'thumb_variants' => ['sm' => 200],
                'thumb_format' => 'webp',
                'thumb_quality' => 82,
                'thumb_algo_version' => 1,
            ];

            $service = new MediaThumbnailService($this->storage, $this->decoder);
            $result = $service->sync($jpeg, $config);
            $this->assertSame(1, $result['generated']);

            $thumbPath = $this->storage->absolutePath('uploads/_cache/2026/01/sha256det/sm_v1.webp');
            $hash1 = hash_file('sha256', $thumbPath);

            @unlink($thumbPath);
            $reason = $thumbPath . '.reason';
            if (is_file($reason)) {
                @unlink($reason);
            }

            $this->decoder->stripCalled = false;
            $result2 = $service->sync($jpeg, $config);
            $this->assertSame(1, $result2['generated']);

            $hash2 = hash_file('sha256', $thumbPath);
            $this->assertSame($hash1, $hash2);
        }

        public function testServeThumbMissingReturns404(): void
        {
            $previous = $_ENV['MEDIA_PUBLIC_MODE'] ?? null;
            $_ENV['MEDIA_PUBLIC_MODE'] = 'all';

            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $repo->create([
                'uuid' => 'u1',
                'disk_path' => 'uploads/2026/01/x.jpg',
                'original_name' => 'x.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1,
                'sha256' => 'sha256missing',
                'uploaded_by' => null,
            ]);

            $request = new Request('GET', '/media/1/thumb/sm', [], [], [], '');
            $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $thumbs = new MediaThumbnailService($this->storage, $this->decoder);
            $controller = new MediaThumbController(null, $service, null, null, null, $this->storage, $thumbs);
            $response = $controller->serve($request, ['id' => 1, 'variant' => 'sm']);

            $this->assertSame(404, $response->getStatus());

            if ($previous === null) {
                unset($_ENV['MEDIA_PUBLIC_MODE']);
            } else {
                $_ENV['MEDIA_PUBLIC_MODE'] = $previous;
            }
        }

        private function createMedia(string $mime, string $diskPath, string $sha256): array
        {
            $absolute = $this->storage->absolutePath($diskPath);
            @mkdir(dirname($absolute), 0775, true);
            file_put_contents($absolute, 'src');

            return [
                'mime_type' => $mime,
                'disk_path' => $diskPath,
                'sha256' => $sha256,
            ];
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

        private function removeDir(string $dir): void
        {
            if (!is_dir($dir)) {
                return;
            }

            $items = scandir($dir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    $this->removeDir($path);
                    continue;
                }
                @unlink($path);
            }

            @rmdir($dir);
        }
    }

    final class FakeDecoder implements ImageDecoderInterface
    {
        public int $calls = 0;
        public bool $stripCalled = false;
        public bool $stripOk = true;
        public ?int $width = 100;
        public ?int $height = 100;

        public function supportsMime(string $mime): bool
        {
            return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
        }

        public function getWidth(string $sourcePath): ?int
        {
            return $this->width;
        }

        public function getHeight(string $sourcePath): ?int
        {
            return $this->height;
        }

        public function stripMetadata(string $path): bool
        {
            $this->stripCalled = true;
            return $this->stripOk;
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
            $this->calls++;
            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
            $payload = hash('sha256', implode('|', [
                $sourcePath,
                $maxWidth,
                $format,
                $quality,
                $maxPixels,
            ]));
            return file_put_contents($targetPath, $payload) !== false;
        }
    }
}
