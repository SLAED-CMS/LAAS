<?php
declare(strict_types=1);

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class RbacMiddlewareTest extends TestCase
{
    public function testDeniedWhenMissingPermission(): void
    {
        $pdo = $this->createPdo();
        $this->createRbacSchema($pdo);

        $rbac = new RbacRepository($pdo);
        $auth = $this->fakeAuth(1, true);
        $middleware = new RbacMiddleware($auth, new AuthorizationService($rbac));
        $request = new Request('GET', '/admin', [], [], ['accept' => 'application/json'], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
    }

    public function testAllowsWhenPermissionGranted(): void
    {
        $pdo = $this->createPdo();
        $this->createRbacSchema($pdo);
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'admin.access', 'Admin access', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec('INSERT INTO role_user (user_id, role_id) VALUES (1, 1)');
        $pdo->exec('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)');

        $rbac = new RbacRepository($pdo);
        $auth = $this->fakeAuth(1, true);
        $middleware = new RbacMiddleware($auth, new AuthorizationService($rbac));
        $request = new Request('GET', '/admin', [], [], ['accept' => 'application/json'], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }

    private function createPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function createRbacSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');
    }

    private function fakeAuth(int $userId, bool $check): AuthInterface
    {
        return new class($userId, $check) implements AuthInterface {
            public function __construct(private int $userId, private bool $check)
            {
            }
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return $this->check ? ['id' => $this->userId] : null; }
            public function check(): bool { return $this->check; }
        };
    }
}
