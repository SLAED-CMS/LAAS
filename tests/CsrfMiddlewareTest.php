<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Security\Csrf;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class CsrfMiddlewareTest extends TestCase
{
    private InMemorySession $session;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();
        $this->session->start();
    }

    public function testAllowsValidToken(): void
    {
        $csrf = new Csrf($this->session);
        $token = $csrf->getToken();
        $middleware = new CsrfMiddleware();
        $request = new Request('POST', '/submit', [], [Csrf::FORM_KEY => $token], [], '');
        $request->setSession($this->session);

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('ok', $response->getBody());
    }

    public function testRejectsInvalidToken(): void
    {
        $csrf = new Csrf($this->session);
        $csrf->getToken();
        $middleware = new CsrfMiddleware();
        $request = new Request('POST', '/submit', [], [Csrf::FORM_KEY => 'bad'], [], '');
        $request->setSession($this->session);

        $response = $middleware->process($request, function (): Response {
            return new Response('ok', 200);
        });

        $this->assertSame(419, $response->getStatus());
        $this->assertSame('419 CSRF Token Mismatch', $response->getBody());
    }
}
