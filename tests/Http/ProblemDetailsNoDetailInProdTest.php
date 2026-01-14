<?php
declare(strict_types=1);

use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsNoDetailInProdTest extends TestCase
{
    public function testProblemDetailsNoDetailWhenDebugOff(): void
    {
        $prev = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';

        try {
            $request = new Request('POST', '/api/v1/ping', [], [], ['accept' => 'application/json'], '{');
            $response = ErrorResponse::respond($request, 'http.invalid_json', [], 400);

            $payload = json_decode($response->getBody(), true);
            $this->assertIsArray($payload);

            $problem = $payload['meta']['problem'] ?? null;
            $this->assertIsArray($problem);
            $this->assertArrayNotHasKey('detail', $problem);
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $prev;
            }
        }
    }
}
