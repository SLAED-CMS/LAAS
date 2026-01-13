<?php
declare(strict_types=1);

use Laas\Http\Middleware\RateLimitMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('security')]
final class CspReportRateLimitTest extends TestCase
{
    public function testCspReportRateLimitTriggers(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.11';

        $root = sys_get_temp_dir() . '/laas-rate-' . uniqid();
        $middleware = new RateLimitMiddleware(new RateLimiter($root), []);
        $this->setProfileConfig($middleware, [
            'profiles' => [
                'csp_report' => ['window' => 60, 'max' => 1],
            ],
            'routes' => [
                ['path' => '/__csp/report', 'methods' => ['POST'], 'profile' => 'csp_report'],
            ],
        ]);

        $request = new Request('POST', '/__csp/report', [], [], [], '{}');
        $next = static fn (): Response => new Response('', 204);

        $first = $middleware->process($request, $next);
        $second = $middleware->process($request, $next);

        $this->assertSame(204, $first->getStatus());
        $this->assertSame(429, $second->getStatus());
    }

    private function setProfileConfig(RateLimitMiddleware $middleware, array $config): void
    {
        $ref = new \ReflectionProperty($middleware, 'profileConfig');
        $ref->setAccessible(true);
        $ref->setValue($middleware, $config);
    }
}
