<?php
declare(strict_types=1);

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class ErrorEnvelopeConsistencyAllHttpCodesTest extends TestCase
{
    public function testCommonHttpErrorsShareEnvelopeShape(): void
    {
        $request = new Request('GET', '/api/v1/ping', [], [], ['accept' => 'application/json'], '');

        $cases = [
            ['code' => ErrorCode::INVALID_REQUEST, 'status' => 400, 'key' => 'error.invalid_request'],
            ['code' => ErrorCode::AUTH_REQUIRED, 'status' => 401, 'key' => 'error.auth_required'],
            ['code' => ErrorCode::RBAC_DENIED, 'status' => 403, 'key' => 'error.rbac_denied'],
            ['code' => ErrorCode::NOT_FOUND, 'status' => 404, 'key' => 'error.not_found'],
            ['code' => 'http.payload_too_large', 'status' => 413, 'key' => 'http.payload_too_large'],
            ['code' => 'http.uri_too_long', 'status' => 414, 'key' => 'http.uri_too_long'],
            ['code' => ErrorCode::RATE_LIMITED, 'status' => 429, 'key' => 'rate_limited'],
            ['code' => 'http.headers_too_large', 'status' => 431, 'key' => 'http.headers_too_large'],
            ['code' => ErrorCode::SERVICE_UNAVAILABLE, 'status' => 503, 'key' => 'service_unavailable'],
        ];

        foreach ($cases as $case) {
            $response = ErrorResponse::respond($request, $case['code']);
            $this->assertSame($case['status'], $response->getStatus(), 'status for ' . $case['key']);

            $payload = json_decode($response->getBody(), true);
            $this->assertIsArray($payload, 'payload for ' . $case['key']);
            $this->assertNull($payload['data'] ?? null, 'data for ' . $case['key']);
            $this->assertFalse($payload['meta']['ok'] ?? true, 'meta.ok for ' . $case['key']);
            $this->assertSame($case['key'], $payload['meta']['error']['key'] ?? null, 'error key for ' . $case['key']);
            $this->assertNotSame('', (string) ($payload['meta']['error']['message'] ?? ''), 'error message for ' . $case['key']);

            $details = $payload['error']['details'] ?? [];
            if (is_array($details)) {
                $this->assertArrayNotHasKey('trace', $details, 'trace key for ' . $case['key']);
                $this->assertArrayNotHasKey('stack', $details, 'stack key for ' . $case['key']);
            }
        }
    }
}
