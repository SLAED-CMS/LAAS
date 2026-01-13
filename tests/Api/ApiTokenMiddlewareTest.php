<?php
declare(strict_types=1);

use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Http\Middleware\ApiTokenAuthMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
#[Group('security')]
final class ApiTokenMiddlewareTest extends TestCase
{
    public function testBearerOkSetsAuthAttributes(): void
    {
        [$db] = $this->createDb();
        $service = $this->createService($db);
        $issued = $service->createToken(1, 'CLI', ['admin.read']);

        $request = new Request('GET', '/api/v1/me', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');

        $captured = [];
        $middleware = new ApiTokenAuthMiddleware($db, ['token_scopes' => ['admin.read']]);
        $response = $middleware->process($request, static function (Request $req) use (&$captured): Response {
            $captured = [
                'user_id' => $req->getAttribute('auth_user_id'),
                'token_id' => $req->getAttribute('auth_token_id'),
                'scopes' => $req->getAttribute('auth_scopes'),
            ];
            return new Response('OK', 200);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, (int) ($captured['user_id'] ?? 0));
        $this->assertSame((int) $issued['token_id'], (int) ($captured['token_id'] ?? 0));
        $this->assertSame(['admin.read'], $captured['scopes'] ?? []);
    }

    public function testInvalidTokenReturnsUnauthorized(): void
    {
        [$db] = $this->createDb();

        $request = new Request('GET', '/api/v1/me', [], [], [
            'authorization' => 'Bearer invalid',
        ], '');

        $middleware = new ApiTokenAuthMiddleware($db, []);
        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(401, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('auth.invalid_token', $payload['error'] ?? null);
    }

    public function testExpiredTokenReturnsUnauthorized(): void
    {
        [$db] = $this->createDb();
        $service = $this->createService($db);
        $expiredAt = date('Y-m-d H:i:s', strtotime('-1 day'));
        $issued = $service->createToken(1, 'CLI', ['admin.read'], $expiredAt);

        $request = new Request('GET', '/api/v1/me', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');

        $middleware = new ApiTokenAuthMiddleware($db, []);
        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(401, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('auth.token_expired', $payload['error'] ?? null);
    }

    /** @return array{0: DatabaseManager, 1: PDO} */
    private function createDb(): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(190) NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            last_login_ip VARCHAR(45) NULL
        )');

        $pdo->exec('CREATE TABLE api_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            name TEXT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            token_prefix TEXT NOT NULL,
            scopes TEXT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');

        $pdo->exec("INSERT INTO users (username, email, password_hash, status, created_at, updated_at)
            VALUES ('admin', 'admin@example.com', '" . password_hash('secret', PASSWORD_DEFAULT) . "', 1, '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return [$db, $pdo];
    }

    private function createService(DatabaseManager $db): ApiTokenService
    {
        return new ApiTokenService($db, [
            'token_scopes' => ['admin.read'],
        ]);
    }
}
