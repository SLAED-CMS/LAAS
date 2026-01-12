<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\Session\SessionManager;
use PHPUnit\Framework\TestCase;

final class SecureCookiesAutoTest extends TestCase
{
    private string $rootPath;
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->serverBackup = $_SERVER;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SERVER = $this->serverBackup;
    }

    public function testSecureCookieAutoEnabledOnHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = new Request('GET', '/', [], [], [], '');
        $manager = new SessionManager($this->rootPath, [
            'session' => [
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ]);
        $manager->start($request);

        $params = session_get_cookie_params();
        $this->assertTrue((bool) ($params['secure'] ?? false));
    }
}
