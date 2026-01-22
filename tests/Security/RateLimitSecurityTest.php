<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\CacheRateLimiterStore;
use Laas\Security\RateLimiter;
use Laas\Support\Cache\CacheFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class RateLimitSecurityTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->clearRateLimit();
    }

    public function testLoginRateLimitTriggers(): void
    {
        $limiter = new RateLimiter($this->rootPath);
        $first = $limiter->hit('login', '127.0.0.1', 60, 1);
        $second = $limiter->hit('login', '127.0.0.1', 60, 1);

        $this->assertTrue($first['allowed']);
        $this->assertFalse($second['allowed']);
    }

    public function testApiRateLimitTriggers(): void
    {
        $middleware = new RateLimitMiddleware(new RateLimiter($this->rootPath), [
            'rate_limit' => [
                'api' => ['window' => 60, 'max' => 0],
            ],
        ]);
        $this->setProfileConfig($middleware, [
            'profiles' => [
                'api_default' => ['window' => 60, 'max' => 0],
            ],
        ]);

        $request = new Request('GET', '/api/v1/ping', [], [], ['accept' => 'application/json'], '');
        $next = static fn(): Response => new Response('ok', 200);

        $this->assertSame(429, $middleware->process($request, $next)->getStatus());
    }

    private function clearRateLimit(): void
    {
        $store = new CacheRateLimiterStore(CacheFactory::create($this->rootPath));
        $store->delete('login:127.0.0.1');
        $store->delete('api_default:127.0.0.1');
    }

    private function setProfileConfig(RateLimitMiddleware $middleware, array $config): void
    {
        $ref = new \ReflectionProperty($middleware, 'profileConfig');
        $ref->setAccessible(true);
        $ref->setValue($middleware, $config);
    }
}
