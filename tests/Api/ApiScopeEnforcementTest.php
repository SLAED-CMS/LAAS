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
final class ApiScopeEnforcementTest extends TestCase
{
    public function testWildcardScopeAllowsAccess(): void
    {
        [$db] = $this->createDb();
        $service = $this->createService($db, ['*', 'ping:read']);
        $issued = $service->createToken(1, 'CLI', ['*']);

        $request = new Request('GET', '/api/v1/ping', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');
        $request->setAttribute('route.pattern', '/api/v1/ping');

        $middleware = new ApiTokenAuthMiddleware($db, [
            'token_scopes' => ['*', 'ping:read'],
            'routes_scopes' => [
                'GET /api/v1/ping' => ['ping:read'],
            ],
        ]);

        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testMissingScopeReturnsForbidden(): void
    {
        [$db] = $this->createDb();
        $service = $this->createService($db, ['ping:read', 'pages:read']);
        $issued = $service->createToken(1, 'CLI', ['pages:read']);

        $request = new Request('GET', '/api/v1/ping', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');
        $request->setAttribute('route.pattern', '/api/v1/ping');

        $middleware = new ApiTokenAuthMiddleware($db, [
            'token_scopes' => ['ping:read', 'pages:read'],
            'routes_scopes' => [
                'GET /api/v1/ping' => ['ping:read'],
            ],
        ]);

        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
    }

    public function testRequiredScopeAllowsAccess(): void
    {
        [$db] = $this->createDb();
        $service = $this->createService($db, ['ping:read']);
        $issued = $service->createToken(1, 'CLI', ['ping:read']);

        $request = new Request('GET', '/api/v1/ping', [], [], [
            'authorization' => 'Bearer ' . $issued['token'],
        ], '');
        $request->setAttribute('route.pattern', '/api/v1/ping');

        $middleware = new ApiTokenAuthMiddleware($db, [
            'token_scopes' => ['ping:read'],
            'routes_scopes' => [
                'GET /api/v1/ping' => ['ping:read'],
            ],
        ]);

        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
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

    private function createService(DatabaseManager $db, array $allowedScopes): ApiTokenService
    {
        return new ApiTokenService($db, [
            'token_scopes' => $allowedScopes,
        ]);
    }
}
