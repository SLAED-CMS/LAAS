<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\MediaServeController;
use Laas\Modules\Media\Controller\MediaThumbController;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\StorageService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class SignedUrlsSecurityTest extends TestCase
{
    private string $rootPath;
    private array $paths = [];

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
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

    public function testInvalidSignatureDenied(): void
    {
        $this->withEnv([
            'MEDIA_PUBLIC_MODE' => 'signed',
            'MEDIA_SIGNED_URLS_ENABLED' => 'true',
            'MEDIA_SIGNED_URL_SECRET' => 'secret',
        ], function (): void {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $media = $this->createMedia($repo, true, 'token1');

            $params = [
                'p' => 'view',
                'exp' => (string) (time() + 300),
                'sig' => 'deadbeef',
            ];
            $request = new Request('GET', $media['url'], $params, [], [], '');
            $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $signer = new MediaSignedUrlService($this->config());
            $storage = new StorageService($this->rootPath);
            $controller = new MediaServeController(null, $service, null, null, $signer, $storage);
            $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

            $this->assertSame(403, $response->getStatus());
        });
    }

    public function testExpiredSignatureDenied(): void
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
            $url = $signer->buildSignedUrl($media['url'], $media['row'], 'view', time() - 10);
            $params = $this->parseQuery($url);

            $request = new Request('GET', $media['url'], $params, [], [], '');
            $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $storage = new StorageService($this->rootPath);
            $controller = new MediaServeController(null, $service, null, null, $signer, $storage);
            $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

            $this->assertSame(403, $response->getStatus());
        });
    }

    public function testValidSignatureAllowsAccess(): void
    {
        $this->withEnv([
            'MEDIA_PUBLIC_MODE' => 'signed',
            'MEDIA_SIGNED_URLS_ENABLED' => 'true',
            'MEDIA_SIGNED_URL_SECRET' => 'secret',
        ], function (): void {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $media = $this->createMedia($repo, true, 'token3');

            $signer = new MediaSignedUrlService($this->config());
            $url = $signer->buildSignedUrl($media['url'], $media['row'], 'view');
            $params = $this->parseQuery($url);

            $request = new Request('GET', $media['url'], $params, [], [], '');
            $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $storage = new StorageService($this->rootPath);
            $controller = new MediaServeController(null, $service, null, null, $signer, $storage);
            $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

            $this->assertSame(200, $response->getStatus());
        });
    }

    public function testScopeDoesNotEscalate(): void
    {
        $this->withEnv([
            'MEDIA_PUBLIC_MODE' => 'signed',
            'MEDIA_SIGNED_URLS_ENABLED' => 'true',
            'MEDIA_SIGNED_URL_SECRET' => 'secret',
        ], function (): void {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $media = $this->createMedia($repo, true, 'token4');

            $thumbDisk = 'uploads/_cache/2026/01/' . $media['sha256'] . '/sm_v1.webp';
            $storage = new StorageService($this->rootPath);
            $thumbPath = $storage->absolutePath($thumbDisk);
            @mkdir(dirname($thumbPath), 0775, true);
            file_put_contents($thumbPath, 'thumb');
            $this->paths[] = $thumbPath;

            $signer = new MediaSignedUrlService($this->config());
            $thumbUrl = '/media/' . $media['id'] . '/thumb/sm';
            $signed = $signer->buildSignedUrl($thumbUrl, $media['row'], 'thumb:sm');
            $params = $this->parseQuery($signed);

            $fileRequest = new Request('GET', $media['url'], $params, [], [], '');
            $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $fileController = new MediaServeController(null, $service, null, null, $signer, $storage);
            $fileResponse = $fileController->serve($fileRequest, ['id' => $media['id'], 'name' => 'file.jpg']);
            $this->assertSame(403, $fileResponse->getStatus());

            $thumbRequest = new Request('GET', $thumbUrl, $params, [], [], '');
            $thumbService = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
            $thumbs = new \Laas\Modules\Media\Service\MediaThumbnailService($storage);
            $thumbController = new MediaThumbController(null, $thumbService, null, null, $signer, $storage, $thumbs);
            $thumbResponse = $thumbController->serve($thumbRequest, ['id' => $media['id'], 'variant' => 'sm']);
            $this->assertSame(200, $thumbResponse->getStatus());
        });
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedMediaTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function createMedia(MediaRepository $repo, bool $isPublic, string $token): array
    {
        $storage = new StorageService($this->rootPath);
        $diskPath = 'uploads/2026/01/' . bin2hex(random_bytes(4)) . '.jpg';
        $absolute = $storage->absolutePath($diskPath);
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
