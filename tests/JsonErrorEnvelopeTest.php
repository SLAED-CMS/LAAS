<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class JsonErrorEnvelopeTest extends TestCase
{
    public function testEnvelopeHasRequestIdAndMeta(): void
    {
        $request = new Request('GET', '/api/test', [], [], ['accept' => 'application/json'], '');
        RequestScope::setRequest($request);
        RequestScope::set('devtools.context', new DevToolsContext(['enabled' => true, 'request_id' => 'req-123']));

        $response = ErrorResponse::respond($request, ErrorCode::INTERNAL, [], 500, [], 'test.source');
        $payload = json_decode($response->getBody(), true);

        $this->assertSame('E_INTERNAL', $payload['error']['code'] ?? null);
        $this->assertSame('req-123', $payload['meta']['request_id'] ?? null);
        $this->assertArrayHasKey('ts', $payload['meta'] ?? []);

        RequestScope::reset();
        RequestScope::setRequest(null);
    }
}
