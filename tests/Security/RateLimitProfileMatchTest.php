<?php
declare(strict_types=1);

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class RateLimitProfileMatchTest extends TestCase
{
    public function testRouteProfileMatchByPath(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.12';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $middleware = new RateLimitMiddleware(new RateLimiter($root), []);
        $this->setProfileConfig($middleware, $this->config());

        $request = new Request('POST', '/login', [], [], [], '');
        $next = static fn (): Response => new Response('OK', 200);

        $first = $middleware->process($request, $next);
        $second = $middleware->process($request, $next);

        $this->assertSame(200, $first->getStatus());
        $this->assertSame(429, $second->getStatus());
    }

    public function testRouteProfileOverrideByName(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.13';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $middleware = new RateLimitMiddleware(new RateLimiter($root), []);
        $this->setProfileConfig($middleware, $this->config());

        $request = new Request('POST', '/admin/settings', [], [], [], '');
        $request->setAttribute('route.name', 'admin.settings.save');
        $next = static fn (): Response => new Response('OK', 200);

        $first = $middleware->process($request, $next);
        $second = $middleware->process($request, $next);

        $this->assertSame(200, $first->getStatus());
        $this->assertSame(429, $second->getStatus());
    }

    private function config(): array
    {
        return [
            'profiles' => [
                'fast' => ['window' => 60, 'max' => 1],
                'slow' => ['window' => 60, 'max' => 3],
            ],
            'routes' => [
                ['path' => '/login', 'methods' => ['POST'], 'profile' => 'fast'],
                ['path' => '/admin', 'methods' => ['POST'], 'match' => 'prefix', 'profile' => 'slow'],
            ],
            'route_names' => [
                'admin.settings.save' => 'fast',
            ],
            'fallback' => null,
        ];
    }

    private function setProfileConfig(RateLimitMiddleware $middleware, array $config): void
    {
        $ref = new \ReflectionProperty($middleware, 'profileConfig');
        $ref->setAccessible(true);
        $ref->setValue($middleware, $config);
    }
}
