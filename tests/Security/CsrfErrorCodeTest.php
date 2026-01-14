<?php
declare(strict_types=1);

use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class CsrfErrorCodeTest extends TestCase
{
    public function testCsrfErrorCode(): void
    {
        $middleware = new CsrfMiddleware();
        $request = new Request('POST', '/admin/save', [], [], ['accept' => 'application/json'], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_CSRF_INVALID', $payload['error']['code'] ?? null);
        $this->assertSame('security.csrf_failed', $payload['meta']['error']['key'] ?? null);
    }
}
