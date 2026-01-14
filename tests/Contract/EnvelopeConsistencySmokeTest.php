<?php
declare(strict_types=1);

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class EnvelopeConsistencySmokeTest extends TestCase
{
    public function testApiErrorEnvelopeShape(): void
    {
        $prev = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';

        $request = new Request('GET', '/api/v1/me', [], [], ['accept' => 'application/json'], '');
        $response = ErrorResponse::respond($request, ErrorCode::API_TOKEN_INVALID, [], 401);

        $this->assertSame(401, $response->getStatus());
        $contentType = (string) ($response->getHeader('Content-Type') ?? '');
        $this->assertStringContainsString('application/json', strtolower($contentType));

        $payload = json_decode($response->getBody(), true);
        $this->assertNull($payload['data'] ?? null);
        $this->assertSame('E_API_TOKEN_INVALID', $payload['error']['code'] ?? null);
        $this->assertFalse($payload['meta']['ok'] ?? true);
        $this->assertSame('auth.invalid_token', $payload['meta']['error']['key'] ?? null);

        if ($prev === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $prev;
        }
    }

    public function testRbacErrorEnvelopeShape(): void
    {
        $prev = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';

        $request = new Request('GET', '/admin/modules', [], [], ['accept' => 'application/json'], '');
        $response = ErrorResponse::respond($request, ErrorCode::RBAC_DENIED, [], 403);

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertNull($payload['data'] ?? null);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
        $this->assertFalse($payload['meta']['ok'] ?? true);
        $this->assertSame('rbac.forbidden', $payload['meta']['error']['key'] ?? null);

        if ($prev === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $prev;
        }
    }
}
