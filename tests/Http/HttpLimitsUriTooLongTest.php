<?php
declare(strict_types=1);

use Laas\Http\Middleware\HttpLimitsMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpLimitsUriTooLongTest extends TestCase
{
    private array $serverBackup = [];
    private array $filesBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->filesBackup = $_FILES;
        $_SERVER['REQUEST_URI'] = '/api/v1/ping?' . str_repeat('a', 50);
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_FILES = $this->filesBackup;
    }

    public function testUriTooLong(): void
    {
        $middleware = new HttpLimitsMiddleware([
            'max_body_bytes' => 2_000_000,
            'max_post_fields' => 200,
            'max_header_bytes' => 32000,
            'max_url_length' => 10,
            'max_files' => 10,
            'max_file_bytes' => 10_000_000,
        ]);

        $request = new Request('GET', '/api/v1/ping', [], [], [
            'accept' => 'application/json',
        ], '');

        $response = $middleware->process($request, static fn (): Response => new Response('ok', 200));

        $this->assertSame(414, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('http.uri_too_long', $payload['meta']['error']['key'] ?? null);
    }
}
