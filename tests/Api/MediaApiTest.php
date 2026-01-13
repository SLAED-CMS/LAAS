<?php
declare(strict_types=1);

use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Http\ErrorCode;
use Laas\Http\Middleware\ApiMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Api\Controller\MediaController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class MediaApiTest extends TestCase
{
    public function testPrivateRequiresPermission(): void
    {
        $prev = $this->setEnv('MEDIA_PUBLIC_MODE', 'private');
        try {
            $db = $this->createDb();
            $controller = new MediaController($db);

            $request = new Request('GET', '/api/v1/media', [], [], [], '');
            $response = $controller->index($request);

            $payload = json_decode($response->getBody(), true);
            $this->assertSame(ErrorCode::RBAC_DENIED, $payload['error']['code'] ?? null);
            $this->assertSame(403, $response->getStatus());
        } finally {
            $this->restoreEnv('MEDIA_PUBLIC_MODE', $prev);
        }
    }

    public function testPublicDownloadRedirects(): void
    {
        $prev = $this->setEnv('MEDIA_PUBLIC_MODE', 'all');
        try {
            $db = $this->createDb();
            $controller = new MediaController($db);

            $request = new Request('GET', '/api/v1/media/1/download', [], [], [], '');
            $response = $controller->download($request, ['id' => 1]);

            $this->assertSame(302, $response->getStatus());
            $this->assertStringContainsString('/media/1/', (string) $response->getHeader('Location'));
        } finally {
            $this->restoreEnv('MEDIA_PUBLIC_MODE', $prev);
        }
    }

    public function testHeadersIncludeNosniff(): void
    {
        $prev = $this->setEnv('MEDIA_PUBLIC_MODE', 'all');
        try {
            $db = $this->createDb();
            $controller = new MediaController($db);
            $middleware = new ApiMiddleware($db, new AuthorizationService(null), [
                'enabled' => true,
                'cors' => ['enabled' => false],
            ], dirname(__DIR__, 2));

            $request = new Request('GET', '/api/v1/media', [], [], [], '');
            $response = $middleware->process($request, static fn (Request $req): Response => $controller->index($req));

            $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        } finally {
            $this->restoreEnv('MEDIA_PUBLIC_MODE', $prev);
        }
    }

    private function createDb(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL UNIQUE,
            disk_path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT NOT NULL,
            sha256 CHAR(64) NULL,
            uploaded_by INT NULL,
            created_at DATETIME NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            public_token VARCHAR(64) NULL
        )');

        $pdo->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, created_at, is_public, public_token)
            VALUES ('uuid-1', 'uploads/file.bin', 'file.bin', 'application/octet-stream', 10, '2026-01-01', 1, 'token')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function setEnv(string $key, string $value): ?string
    {
        $prev = $_ENV[$key] ?? null;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
        return $prev;
    }

    private function restoreEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
            return;
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
