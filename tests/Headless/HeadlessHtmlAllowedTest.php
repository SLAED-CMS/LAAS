<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Auth\TotpService;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\Request;
use Laas\Modules\Users\Controller\AuthController;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HeadlessHtmlAllowedTest extends TestCase
{
    public function testAllowlistedHtmlIsServed(): void
    {
        $prevHeadless = $_ENV['APP_HEADLESS'] ?? null;
        $prevAllowlist = $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] ?? null;
        $_ENV['APP_HEADLESS'] = 'true';
        $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] = '/login';

        try {
            $pdo = SecurityTestHelper::createSqlitePdo();
            $db = SecurityTestHelper::dbManagerFromPdo($pdo);

            $request = new Request('GET', '/login', [], [], ['accept' => 'text/html'], '');
            RequestScope::setRequest($request);
            $view = SecurityTestHelper::createView($db, $request, 'default');

            $users = new UsersRepository($db->pdo());
            $controller = new AuthController($view, new NullAuthService(), $users, new TotpService());
            $response = $controller->showLogin($request);

            $this->assertSame(200, $response->getStatus());
            $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
            $this->assertNotSame('', $response->getBody());
        } finally {
            RequestScope::reset();
            RequestScope::setRequest(null);
            if ($prevHeadless === null) {
                unset($_ENV['APP_HEADLESS']);
            } else {
                $_ENV['APP_HEADLESS'] = $prevHeadless;
            }
            if ($prevAllowlist === null) {
                unset($_ENV['APP_HEADLESS_HTML_ALLOWLIST']);
            } else {
                $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] = $prevAllowlist;
            }
        }
    }
}
