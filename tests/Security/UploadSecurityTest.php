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
    require_once __DIR__ . '/Support/SecurityTestHelper.php';

    use Laas\Database\DatabaseManager;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\MediaUploadService;
    use Laas\Modules\Media\Service\MimeSniffer;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;
    use Tests\Security\Support\SecurityTestHelper;

    #[Group('security')]
    final class UploadSecurityTest extends TestCase
    {
        private string $rootPath;
        private string $tmpRoot;
        private array $cleanup = [];

        protected function setUp(): void
        {
            $this->rootPath = SecurityTestHelper::rootPath();
            $this->tmpRoot = sys_get_temp_dir() . '/laas_sec_' . bin2hex(random_bytes(4));
            @mkdir($this->tmpRoot . '/storage/uploads', 0775, true);
            @mkdir($this->tmpRoot . '/storage/uploads/quarantine', 0775, true);
        }

        protected function tearDown(): void
        {
            foreach ($this->cleanup as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $this->cleanup = [];
            $this->removeDir($this->tmpRoot . '/storage');
            if (is_dir($this->tmpRoot)) {
                @rmdir($this->tmpRoot);
            }
        }

        public function testSvgRejectedAndNoFinalFile(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->tmpRoot);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $tmp = $this->createTempFile("<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\"></svg>");
            $file = $this->fakeUpload($tmp, 'icon.svg');

            $result = $service->upload($file, 'icon.svg', $this->config(), 1);
            $this->assertSame('error', $result['status'] ?? '');

            $files = glob($this->tmpRoot . '/storage/uploads/*/*/*') ?: [];
            $this->assertSame([], $files);
        }

        public function testMimeSpoofingRejected(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->tmpRoot);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $tmp = $this->createTempFile("not an image");
            $file = $this->fakeUpload($tmp, 'image.jpg');

            $result = $service->upload($file, 'image.jpg', $this->config(), 1);
            $this->assertSame('error', $result['status'] ?? '');
        }

        public function testSizeLimitRejected(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->tmpRoot);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $tmp = $this->createTempFile(str_repeat('a', 1024));
            $file = $this->fakeUpload($tmp, 'file.png');

            $config = $this->config();
            $config['max_bytes'] = 10;

            $result = $service->upload($file, 'file.png', $config, 1);
            $this->assertSame('error', $result['status'] ?? '');
        }

        public function testFilenameTraversalDoesNotAffectDiskPath(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->tmpRoot);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $tmp = $this->createTempFile(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '');
            $file = $this->fakeUpload($tmp, '../evil.png');

            $result = $service->upload($file, '../evil.png', $this->config(), 1);
            $this->assertSame('stored', $result['status'] ?? '');

            $row = $repo->findById((int) ($result['id'] ?? 0));
            $diskPath = (string) ($row['disk_path'] ?? '');

            $this->assertStringNotContainsString('..', $diskPath);
        }

        public function testDeduplicationByHash(): void
        {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $storage = new StorageService($this->tmpRoot);
            $service = new MediaUploadService($repo, $storage, new MimeSniffer());

            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            $tmp1 = $this->createTempFile($data);
            $tmp2 = $this->createTempFile($data);

            $result1 = $service->upload($this->fakeUpload($tmp1, 'file.png'), 'file.png', $this->config(), 1);
            $result2 = $service->upload($this->fakeUpload($tmp2, 'file.png'), 'file.png', $this->config(), 1);

            $this->assertSame('stored', $result1['status'] ?? '');
            $this->assertSame('deduped', $result2['status'] ?? '');
            $this->assertSame($result1['id'] ?? null, $result2['id'] ?? null);
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = SecurityTestHelper::createSqlitePdo();
            SecurityTestHelper::seedMediaTable($pdo);
            return SecurityTestHelper::dbManagerFromPdo($pdo);
        }

        private function config(): array
        {
            $config = require $this->rootPath . '/config/media.php';
            return is_array($config) ? $config : [];
        }

        private function createTempFile(string $contents): string
        {
            $path = tempnam(sys_get_temp_dir(), 'laas_sec_upload_');
            if ($path === false) {
                $path = sys_get_temp_dir() . '/laas_sec_' . bin2hex(random_bytes(6));
            }
            file_put_contents($path, $contents);
            $this->cleanup[] = $path;
            return $path;
        }

        private function fakeUpload(string $tmp, string $name): array
        {
            $size = is_file($tmp) ? (int) filesize($tmp) : 0;
            return [
                'name' => $name,
                'type' => '',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];
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
                } else {
                    @unlink($full);
                }
            }
            @rmdir($path);
        }
    }
}
