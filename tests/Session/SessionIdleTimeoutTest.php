<?php
declare(strict_types=1);

use Laas\Http\Middleware\SessionMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;
use Laas\Session\SessionFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class SessionIdleTimeoutTest extends TestCase
{
    public function testIdleTimeoutInvalidatesSession(): void
    {
        $root = sys_get_temp_dir() . '/laas_session_idle_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/sessions', 0775, true);
        @mkdir($root . '/storage/cache/data', 0775, true);

        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $session->set('_last_activity', time() - 61);

        $factory = new SessionFactory(['driver' => 'native'], null, $root);
        $manager = new SessionManager($root, ['session' => []], $factory);
        $middleware = new SessionMiddleware($manager, ['idle_ttl' => 1, 'absolute_ttl' => 0], null, $session, $root);

        $request = new Request('GET', '/admin', [], [], ['accept' => 'application/json'], '');
        $response = $middleware->process($request, static fn(Request $req): Response => new Response('OK', 200));

        $this->assertSame(401, $response->getStatus());
        $this->assertSame(1, $session->regenerateIdCalls);
        $this->assertSame([], $session->all());
    }
}
