<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/Security/Support/SecurityTestHelper.php';

    use Laas\Database\DatabaseManager;
    use Laas\Http\Request;
    use Laas\Modules\Media\Controller\MediaServeController;
    use Laas\Modules\Media\Controller\MediaThumbController;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\MediaSignedUrlService;
    use Laas\Modules\Media\Service\StorageService;
    use PHPUnit\Framework\TestCase;
    use Tests\Security\Support\SecurityTestHelper;

    final class MediaSignedUrlTest extends TestCase
    {
        private string $rootPath;
        private StorageService $storage;
        private array $paths = [];

        protected function setUp(): void
        {
            $this->rootPath = dirname(__DIR__);
            $this->storage = new StorageService($this->rootPath);
        }

        protected function tearDown(): void
        {
            foreach ($this->paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $this->paths = [];
        }

        public function testSignedUrlValidAllowsAccessWithoutRbac(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'signed',
                'MEDIA_SIGNED_URLS_ENABLED' => 'true',
                'MEDIA_SIGNED_URL_SECRET' => 'secret',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, true, 'token1');

                $signer = new MediaSignedUrlService($this->config());
                $url = $signer->buildSignedUrl($media['url'], $media['row'], 'view');
                $params = $this->parseQuery($url);

                $request = new Request('GET', $media['url'], $params, [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $controller = new MediaServeController(null, $service, null, null, $signer, $this->storage);
                $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

                $this->assertSame(200, $response->getStatus());
            });
        }

        public function testSignedUrlExpiredDenied(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'signed',
                'MEDIA_SIGNED_URLS_ENABLED' => 'true',
                'MEDIA_SIGNED_URL_SECRET' => 'secret',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, true, 'token2');

                $signer = new MediaSignedUrlService($this->config());
                $exp = time() - 10;
                $url = $signer->buildSignedUrl($media['url'], $media['row'], 'view', $exp);
                $params = $this->parseQuery($url);

                $request = new Request('GET', $media['url'], $params, [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $controller = new MediaServeController(null, $service, null, null, $signer, $this->storage);
                $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

                $this->assertSame(403, $response->getStatus());
            });
        }

        public function testSignedUrlInvalidDenied(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'signed',
                'MEDIA_SIGNED_URLS_ENABLED' => 'true',
                'MEDIA_SIGNED_URL_SECRET' => 'secret',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, true, 'token3');

                $params = [
                    'p' => 'view',
                    'exp' => (string) (time() + 600),
                    'sig' => 'deadbeef',
                ];

                $request = new Request('GET', $media['url'], $params, [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $signer = new MediaSignedUrlService($this->config());
                $controller = new MediaServeController(null, $service, null, null, $signer, $this->storage);
                $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

                $this->assertSame(403, $response->getStatus());
            });
        }

        public function testPrivateModeRequiresRbac(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'private',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, true, 'token4');

                $request = new Request('GET', $media['url'], [], [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $signer = new MediaSignedUrlService($this->config());
                $controller = new MediaServeController(null, $service, null, null, $signer, $this->storage);
                $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

                $this->assertSame(403, $response->getStatus());
            });
        }

        public function testPublicAllModeDoesNotRequireRbac(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'all',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, false, null);

                $request = new Request('GET', $media['url'], [], [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $signer = new MediaSignedUrlService($this->config());
                $controller = new MediaServeController(null, $service, null, null, $signer, $this->storage);
                $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

                $this->assertSame(200, $response->getStatus());
            });
        }

        public function testThumbSignedWorks(): void
        {
            $this->withEnv([
                'MEDIA_PUBLIC_MODE' => 'signed',
                'MEDIA_SIGNED_URLS_ENABLED' => 'true',
                'MEDIA_SIGNED_URL_SECRET' => 'secret',
            ], function (): void {
                $db = $this->createDatabase();
                $repo = new MediaRepository($db);
                $media = $this->createMedia($repo, true, 'token5');

                $thumbDisk = 'uploads/_cache/2026/01/' . $media['sha256'] . '/sm_v1.webp';
                $thumbPath = $this->storage->absolutePath($thumbDisk);
                @mkdir(dirname($thumbPath), 0775, true);
                file_put_contents($thumbPath, 'thumb');
                $this->paths[] = $thumbPath;

                $signer = new MediaSignedUrlService($this->config());
                $thumbUrl = '/media/' . $media['id'] . '/thumb/sm';
                $url = $signer->buildSignedUrl($thumbUrl, $media['row'], 'thumb:sm');
                $params = $this->parseQuery($url);

                $request = new Request('GET', $thumbUrl, $params, [], [], '');
                $service = new \Laas\Domain\Media\MediaService($db, [], SecurityTestHelper::rootPath());
                $thumbs = new \Laas\Modules\Media\Service\MediaThumbnailService($this->storage);
                $controller = new MediaThumbController(null, $service, null, null, $signer, $this->storage, $thumbs);
                $response = $controller->serve($request, ['id' => $media['id'], 'variant' => 'sm']);

                $this->assertSame(200, $response->getStatus());
            });
        }

        public function testConstantTimeCompareBehavior(): void
        {
            $signer = new MediaSignedUrlService([
                'signed_urls_enabled' => true,
                'signed_url_secret' => 'secret',
            ]);

            $this->assertTrue($signer->signaturesEqual('abc', 'abc'));
            $this->assertFalse($signer->signaturesEqual('abc', 'abd'));
            $this->assertFalse($signer->signaturesEqual('abc', 'ab'));
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

        private function createMedia(MediaRepository $repo, bool $isPublic, ?string $token): array
        {
            $diskPath = 'uploads/2026/01/' . bin2hex(random_bytes(4)) . '.jpg';
            $absolute = $this->storage->absolutePath($diskPath);
            @mkdir(dirname($absolute), 0775, true);
            file_put_contents($absolute, 'file');
            $this->paths[] = $absolute;

            $id = $repo->create([
                'uuid' => bin2hex(random_bytes(8)),
                'disk_path' => $diskPath,
                'original_name' => 'file.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 4,
                'sha256' => bin2hex(random_bytes(16)),
                'uploaded_by' => null,
                'is_public' => $isPublic,
                'public_token' => $token,
            ]);

            $row = $repo->findById($id);

            return [
                'id' => $id,
                'url' => '/media/' . $id . '/file.jpg',
                'row' => $row,
                'sha256' => (string) ($row['sha256'] ?? ''),
            ];
        }

        private function parseQuery(?string $url): array
        {
            if ($url === null || $url === '') {
                return [];
            }

            $parts = parse_url($url);
            $query = [];
            if (isset($parts['query'])) {
                parse_str($parts['query'], $query);
            }

            return $query;
        }

        private function config(): array
        {
            $path = $this->rootPath . '/config/media.php';
            $config = is_file($path) ? require $path : [];
            return is_array($config) ? $config : [];
        }

        private function withEnv(array $vars, callable $callback): void
        {
            $backup = [];
            foreach ($vars as $key => $value) {
                $backup[$key] = $_ENV[$key] ?? null;
                $_ENV[$key] = (string) $value;
            }

            try {
                $callback();
            } finally {
                foreach ($vars as $key => $_) {
                    if ($backup[$key] === null) {
                        unset($_ENV[$key]);
                    } else {
                        $_ENV[$key] = $backup[$key];
                    }
                }
            }
        }
    }
}
