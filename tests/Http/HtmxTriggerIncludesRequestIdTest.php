<?php
declare(strict_types=1);

use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class HtmxTriggerIncludesRequestIdTest extends TestCase
{
    public function testHtmxTriggerIncludesRequestId(): void
    {
        $requestId = 'req-htmx-1';
        try {
            $request = new Request('POST', '/api/v1/ping', [], [], [
                'accept' => 'application/json',
                'hx-request' => 'true',
            ], '{');
            RequestScope::setRequest($request);
            $prev = RequestScope::get('request.id');
            RequestScope::set('request.id', $requestId);

            $response = ErrorResponse::respond($request, 'http.invalid_json', [], 400);
            $header = $response->getHeader('HX-Trigger');

            $this->assertNull($header);

            $payload = json_decode($response->getBody(), true);
            $this->assertIsArray($payload);
            $this->assertSame($requestId, $payload['meta']['events'][0]['request_id'] ?? null);
        } finally {
            RequestScope::setRequest(null);
            if (!isset($prev) || $prev === null) {
                RequestScope::forget('request.id');
            } else {
                RequestScope::set('request.id', $prev);
            }
        }
    }
}
