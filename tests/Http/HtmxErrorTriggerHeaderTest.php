<?php
declare(strict_types=1);

use Laas\Http\Middleware\CsrfMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class HtmxErrorTriggerHeaderTest extends TestCase
{
    public function testHtmxErrorIncludesTriggerHeader(): void
    {
        $session = new InMemorySession();
        $session->start();

        $request = new Request('POST', '/admin/settings', [], [], [
            'hx-request' => 'true',
        ], '');
        $request->setSession($session);

        $middleware = new CsrfMiddleware();
        $response = $middleware->process($request, static fn(): Response => new Response('ok', 200));

        $this->assertSame(403, $response->getStatus());
        $header = $response->getHeader('HX-Trigger');
        $this->assertNotNull($header);

        $payload = json_decode($header, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('laas:toast', $payload);

        $toast = $payload['laas:toast'];
        $this->assertSame('danger', $toast['type'] ?? null);
        $this->assertSame('security.csrf_failed', $toast['code'] ?? null);
        $this->assertNotSame('', (string) ($toast['message'] ?? ''));
        $this->assertNotSame('', (string) ($toast['request_id'] ?? ''));
    }
}
