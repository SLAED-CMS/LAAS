<?php
declare(strict_types=1);

use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Http\Middleware\ApiMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
#[Group('security')]
final class CorsTest extends TestCase
{
    public function testCorsDisabledByDefault(): void
    {
        $middleware = new ApiMiddleware($this->createDb(), new AuthorizationService(null), [
            'enabled' => true,
            'cors' => ['enabled' => false],
        ], dirname(__DIR__, 2));

        $request = new Request('OPTIONS', '/api/v1/pages', [], [], [
            'origin' => 'https://example.com',
            'access-control-request-method' => 'GET',
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(403, $response->getStatus());
    }

    public function testCorsAllowlistWorks(): void
    {
        $middleware = new ApiMiddleware($this->createDb(), new AuthorizationService(null), [
            'enabled' => true,
            'cors' => [
                'enabled' => true,
                'origins' => ['https://example.com'],
                'methods' => ['GET'],
                'headers' => ['Authorization'],
            ],
        ], dirname(__DIR__, 2));

        $request = new Request('OPTIONS', '/api/v1/pages', [], [], [
            'origin' => 'https://example.com',
            'access-control-request-method' => 'GET',
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(204, $response->getStatus());
        $this->assertSame('https://example.com', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testCorsRejectsUnknownOrigin(): void
    {
        $middleware = new ApiMiddleware($this->createDb(), new AuthorizationService(null), [
            'enabled' => true,
            'cors' => [
                'enabled' => true,
                'origins' => ['https://example.com'],
            ],
        ], dirname(__DIR__, 2));

        $request = new Request('OPTIONS', '/api/v1/pages', [], [], [
            'origin' => 'https://evil.test',
            'access-control-request-method' => 'GET',
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(403, $response->getStatus());
    }

    public function testSimpleRequestAddsCorsHeadersWhenAllowed(): void
    {
        $middleware = new ApiMiddleware($this->createDb(), new AuthorizationService(null), [
            'enabled' => true,
            'cors' => [
                'enabled' => true,
                'origins' => ['https://example.com'],
                'methods' => ['GET'],
                'headers' => ['Authorization'],
            ],
        ], dirname(__DIR__, 2));

        $request = new Request('GET', '/api/v1/pages', [], [], [
            'origin' => 'https://example.com',
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame('https://example.com', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function testSimpleRequestNoHeadersWhenNotAllowed(): void
    {
        $middleware = new ApiMiddleware($this->createDb(), new AuthorizationService(null), [
            'enabled' => true,
            'cors' => [
                'enabled' => true,
                'origins' => ['https://example.com'],
            ],
        ], dirname(__DIR__, 2));

        $request = new Request('GET', '/api/v1/pages', [], [], [
            'origin' => 'https://evil.test',
        ], '');

        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    private function createDb(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
