<?php
declare(strict_types=1);

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Http\Middleware\RbacMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class RbacDeniedErrorCodeTest extends TestCase
{
    public function testRbacDeniedErrorCode(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return ['id' => 1]; }
            public function check(): bool { return true; }
        };
        $authorization = new AuthorizationService(null);

        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedPagesTable($pdo);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);

        $request = new Request('GET', '/admin', [], [], ['accept' => 'application/json'], '');
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $middleware = new RbacMiddleware($auth, $authorization, $view);

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
    }
}
