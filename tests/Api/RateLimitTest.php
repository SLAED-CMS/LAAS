<?php
declare(strict_types=1);

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
#[Group('security')]
final class RateLimitTest extends TestCase
{
    public function testApiBucketEnforced(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $limiter = new RateLimiter($root);
        $middleware = new RateLimitMiddleware($limiter, [
            'rate_limit' => [
                'api' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);
        $this->setProfileConfig($middleware, [
            'profiles' => [
                'api_default' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);

        $request = new Request('GET', '/api/v1/pages', [], [], [], '');
        $ok = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));
        $blocked = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $ok->getStatus());
        $this->assertSame(429, $blocked->getStatus());
    }

    public function testTokenHashIsolatesBuckets(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $limiter = new RateLimiter($root);
        $middleware = new RateLimitMiddleware($limiter, [
            'rate_limit' => [
                'api' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);
        $this->setProfileConfig($middleware, [
            'profiles' => [
                'api_default' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);

        $requestA = new Request('GET', '/api/v1/pages', [], [], [], '');
        $requestA->setAttribute('api.token', ['token_hash' => 'hash-a']);
        $requestB = new Request('GET', '/api/v1/pages', [], [], [], '');
        $requestB->setAttribute('api.token', ['token_hash' => 'hash-b']);

        $allowedA = $middleware->process($requestA, static fn (Request $req): Response => new Response('OK', 200));
        $blockedA = $middleware->process($requestA, static fn (Request $req): Response => new Response('OK', 200));
        $allowedB = $middleware->process($requestB, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $allowedA->getStatus());
        $this->assertSame(429, $blockedA->getStatus());
        $this->assertSame(200, $allowedB->getStatus());
    }

    public function testIpFallbackWhenNoToken(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.9';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $limiter = new RateLimiter($root);
        $middleware = new RateLimitMiddleware($limiter, [
            'rate_limit' => [
                'api' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);
        $this->setProfileConfig($middleware, [
            'profiles' => [
                'api_default' => ['window' => 60, 'max' => 1, 'burst' => 1],
            ],
        ]);

        $request = new Request('GET', '/api/v1/pages', [], [], [], '');
        $ok = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));
        $blocked = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $ok->getStatus());
        $this->assertSame(429, $blocked->getStatus());
    }

    private function setProfileConfig(RateLimitMiddleware $middleware, array $config): void
    {
        $ref = new \ReflectionProperty($middleware, 'profileConfig');
        $ref->setAccessible(true);
        $ref->setValue($middleware, $config);
    }
}
