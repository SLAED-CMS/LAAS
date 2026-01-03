<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        session_start();
        $_SESSION = [];
    }

    public function testAllowsValidToken(): void
    {
        $csrf = new Csrf();
        $token = $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);
        $request = new Request('POST', '/submit', [], [Csrf::FORM_KEY => $token], [], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }

    public function testRejectsInvalidToken(): void
    {
        $csrf = new Csrf();
        $csrf->getToken();
        $middleware = new CsrfMiddleware($csrf);
        $request = new Request('POST', '/submit', [], [Csrf::FORM_KEY => 'bad'], [], '');

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(419, $response->getStatus());
        $this->assertSame('419 CSRF Token Mismatch', $response->getBody());
    }
}
