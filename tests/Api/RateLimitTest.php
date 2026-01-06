<?php
declare(strict_types=1);

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    public function testApiBucketEnforced(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $limiter = new RateLimiter($root);
        $middleware = new RateLimitMiddleware($limiter, [
            'rate_limit' => [
                'api' => ['window' => 60, 'max' => 1],
            ],
        ]);

        $request = new Request('GET', '/api/v1/pages', [], [], [], '');
        $ok = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));
        $blocked = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $ok->getStatus());
        $this->assertSame(429, $blocked->getStatus());
    }
}
