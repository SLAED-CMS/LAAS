<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Auth\AuthInterface;
use Laas\Http\Request;
use Laas\Modules\Users\Controller\AuthController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class OpenRedirectSecurityTest extends TestCase
{
    public function testLoginIgnoresExternalNextParam(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return true; }
            public function logout(): void {}
            public function user(): ?array { return ['id' => 1]; }
            public function check(): bool { return true; }
        };

        $request = new Request('POST', '/login', ['next' => 'https://evil.tld'], [
            'username' => 'admin',
            'password' => 'secret',
        ], [], '');
        $db = SecurityTestHelper::dbManagerFromPdo(SecurityTestHelper::createSqlitePdo());
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $controller = new AuthController($view, $auth);

        $response = $controller->doLogin($request);
        $this->assertSame('/admin', $response->getHeader('Location'));
    }

    public function testLogoutIgnoresExternalNextParam(): void
    {
        $auth = new class implements AuthInterface {
            public function attempt(string $username, string $password, string $ip): bool { return false; }
            public function logout(): void {}
            public function user(): ?array { return null; }
            public function check(): bool { return false; }
        };

        $request = new Request('POST', '/logout', ['next' => '//evil.tld'], [], [], '');
        $db = SecurityTestHelper::dbManagerFromPdo(SecurityTestHelper::createSqlitePdo());
        $view = SecurityTestHelper::createView($db, $request, 'default');
        $controller = new AuthController($view, $auth);

        $response = $controller->doLogout($request);
        $this->assertSame('/', $response->getHeader('Location'));
    }
}
