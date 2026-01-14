<?php
declare(strict_types=1);

use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class CsrfFailureReturns403EnvelopeTest extends TestCase
{
    public function testReturns403Envelope(): void
    {
        $session = new InMemorySession();
        $session->start();

        $request = new Request('POST', '/admin/settings', [], [], ['accept' => 'application/json'], '');
        $request->setSession($session);

        $middleware = new CsrfMiddleware();
        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_CSRF_INVALID', $payload['error']['code'] ?? null);
        $this->assertSame('security.csrf_failed', $payload['meta']['error']['key'] ?? null);
    }
}
