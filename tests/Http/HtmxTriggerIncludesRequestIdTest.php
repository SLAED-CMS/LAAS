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
        $prev = RequestScope::get('request.id');
        RequestScope::set('request.id', $requestId);

        try {
            $request = new Request('POST', '/api/v1/ping', [], [], [
                'accept' => 'application/json',
                'hx-request' => 'true',
            ], '{');

            $response = ErrorResponse::respond($request, 'http.invalid_json', [], 400);
            $header = $response->getHeader('HX-Trigger');

            $this->assertNotNull($header);
            $payload = json_decode($header, true);
            $this->assertIsArray($payload);
            $this->assertSame($requestId, $payload['laas:error']['request_id'] ?? null);
        } finally {
            if ($prev === null) {
                RequestScope::forget('request.id');
            } else {
                RequestScope::set('request.id', $prev);
            }
        }
    }
}
