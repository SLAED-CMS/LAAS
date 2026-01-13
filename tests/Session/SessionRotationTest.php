<?php
declare(strict_types=1);

use Laas\Http\Middleware\SessionMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;
use Laas\Session\SessionFactory;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheKey;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class SessionRotationTest extends TestCase
{
    public function testSessionRegeneratesOnRoleChangeMarker(): void
    {
        $root = sys_get_temp_dir() . '/laas_session_rotation_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/sessions', 0775, true);
        @mkdir($root . '/storage/cache/data', 0775, true);

        $cache = CacheFactory::create($root);
        $marker = time();
        $cache->set(CacheKey::sessionRbacVersion(5), $marker, 3600);

        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 5);

        $factory = new SessionFactory(['driver' => 'native'], null, $root);
        $manager = new SessionManager($root, ['session' => []], $factory);
        $middleware = new SessionMiddleware($manager, ['idle_ttl' => 0, 'absolute_ttl' => 0], null, $session, $root);

        $request = new Request('GET', '/admin', [], [], ['accept' => 'application/json'], '');
        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $session->regenerateIdCalls);
        $this->assertSame($marker, $session->get('_rbac_version'));
    }
}
