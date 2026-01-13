<?php
declare(strict_types=1);

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimitErrorIncludesRetryAfterTest extends TestCase
{
    public function testRateLimitIncludesRetryAfter(): void
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

        $request = new Request('GET', '/api/v1/pages', [], [], ['accept' => 'application/json'], '');
        $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));
        $blocked = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(429, $blocked->getStatus());
        $payload = json_decode($blocked->getBody(), true);
        $this->assertSame('E_RATE_LIMITED', $payload['error']['code'] ?? null);
        $this->assertArrayHasKey('retry_after', $payload['error']['details'] ?? []);
        $this->assertSame($blocked->getHeader('Retry-After'), (string) $payload['error']['details']['retry_after']);
    }

    private function setProfileConfig(RateLimitMiddleware $middleware, array $config): void
    {
        $ref = new \ReflectionProperty($middleware, 'profileConfig');
        $ref->setAccessible(true);
        $ref->setValue($middleware, $config);
    }
}
