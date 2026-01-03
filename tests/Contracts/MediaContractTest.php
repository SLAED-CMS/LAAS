<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\MediaServeController;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\StorageService;
use PHPUnit\Framework\TestCase;

final class MediaContractTest extends TestCase
{
    private string $rootPath;
    private StorageService $storage;
    private array $paths = [];

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
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

    public function testMediaServeMissingReturns404(): void
    {
        $db = $this->createDatabase();
        $request = new Request('GET', '/media/999/file.jpg', [], [], [], '');
        $controller = new MediaServeController(null, $db);
        $response = $controller->serve($request, ['id' => 999, 'name' => 'file.jpg']);

        $this->assertSame(404, $response->getStatus());
    }

    public function testMediaServeIncludesNosniffHeader(): void
    {
        $this->withEnv(['MEDIA_PUBLIC_MODE' => 'all'], function (): void {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $media = $this->createMedia($repo, false, null);

            $request = new Request('GET', $media['url'], [], [], [], '');
            $controller = new MediaServeController(null, $db);
            $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

            $this->assertSame(200, $response->getStatus());
            $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        });
    }

    public function testSignedUrlContractWhenEnabled(): void
    {
        $config = $this->config();
        $enabled = (bool) ($config['signed_urls_enabled'] ?? false);
        $secret = (string) ($config['signed_url_secret'] ?? '');

        if (!$enabled || $secret === '') {
            $this->markTestSkipped('Signed URLs disabled for test env.');
        }

        $this->withEnv([
            'MEDIA_PUBLIC_MODE' => 'signed',
            'MEDIA_SIGNED_URLS_ENABLED' => 'true',
            'MEDIA_SIGNED_URL_SECRET' => $secret,
        ], function () use ($secret): void {
            $db = $this->createDatabase();
            $repo = new MediaRepository($db);
            $media = $this->createMedia($repo, true, 'token1');

            $signer = new MediaSignedUrlService([
                'signed_urls_enabled' => true,
                'signed_url_secret' => $secret,
                'signed_url_ttl' => 600,
            ]);
            $url = $signer->buildSignedUrl($media['url'], $media['row'], 'view');
            $params = $this->parseQuery($url);

            $request = new Request('GET', $media['url'], $params, [], [], '');
            $controller = new MediaServeController(null, $db);
            $response = $controller->serve($request, ['id' => $media['id'], 'name' => 'file.jpg']);

            $this->assertSame(200, $response->getStatus());
        });
    }

    private function createDatabase(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
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
