<?php
declare(strict_types=1);

use Laas\Auth\AuthInterface;
use Laas\Http\Middleware\AuthMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    public function testAdminRequiresAuth(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return null; }
            public function check(): bool { return false; }
        };

        $middleware = new AuthMiddleware($auth);
        $request = new Request('GET', '/admin', [], [], [], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(302, $response->getStatus());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function testNonAdminPassesThrough(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return null; }
            public function check(): bool { return false; }
        };

        $middleware = new AuthMiddleware($auth);
        $request = new Request('GET', '/', [], [], [], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }
}
