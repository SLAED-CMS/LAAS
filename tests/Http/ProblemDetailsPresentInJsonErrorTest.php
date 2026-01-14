<?php
declare(strict_types=1);

use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsPresentInJsonErrorTest extends TestCase
{
    public function testProblemDetailsPresentInJsonError(): void
    {
        $request = new Request('POST', '/api/v1/ping', [], [], ['accept' => 'application/json'], '{');
        $response = ErrorResponse::respond($request, 'http.invalid_json', [], 400);

        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);

        $problem = $payload['meta']['problem'] ?? null;
        $this->assertIsArray($problem);
        $this->assertSame('laas:error/http.invalid_json', $problem['type'] ?? null);
        $this->assertSame(400, $problem['status'] ?? null);
        $this->assertSame($payload['meta']['request_id'] ?? null, $problem['instance'] ?? null);
        $this->assertNotSame('', (string) ($problem['title'] ?? ''));
    }
}
