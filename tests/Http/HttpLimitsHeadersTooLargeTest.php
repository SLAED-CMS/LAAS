<?php
declare(strict_types=1);

use Laas\Http\Middleware\HttpLimitsMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class HttpLimitsHeadersTooLargeTest extends TestCase
{
    private array $serverBackup = [];
    private array $filesBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->filesBackup = $_FILES;
        $_SERVER['REQUEST_URI'] = '/api/v1/ping';
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_FILES = $this->filesBackup;
    }

    public function testHeadersTooLarge(): void
    {
        $middleware = new HttpLimitsMiddleware([
            'max_body_bytes' => 2_000_000,
            'max_post_fields' => 200,
            'max_header_bytes' => 20,
            'max_url_length' => 2048,
            'max_files' => 10,
            'max_file_bytes' => 10_000_000,
        ]);

        $request = new Request('GET', '/api/v1/ping', [], [], [
            'accept' => 'application/json',
            'x-test' => str_repeat('a', 50),
        ], '');

        $response = $middleware->process($request, static fn (): Response => new Response('ok', 200));

        $this->assertSame(431, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('http.headers_too_large', $payload['meta']['error']['key'] ?? null);
    }
}
