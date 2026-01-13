<?php
declare(strict_types=1);

use Laas\Session\SessionFactory;
use PHPUnit\Framework\TestCase;

final class SessionCookieFlagsTest extends TestCase
{
    public function testCookiePolicyAppliesDefaultsAndHttpsSecure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $previousParams = session_get_cookie_params();
        $previousName = session_name();

        $factory = new SessionFactory([
            'name' => 'TESTSESSID',
            'cookie_domain' => 'example.test',
            'cookie_secure' => false,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'lifetime' => 0,
        ]);

        $factory->applyCookiePolicy(true);

        $params = session_get_cookie_params();
        $this->assertSame('Lax', (string) ($params['samesite'] ?? ''));
        $this->assertTrue((bool) ($params['secure'] ?? false));
        $this->assertTrue((bool) ($params['httponly'] ?? false));
        $this->assertSame('/', (string) ($params['path'] ?? ''));
        $this->assertSame('example.test', (string) ($params['domain'] ?? ''));

        session_name($previousName);
        session_set_cookie_params($previousParams);
    }
}
