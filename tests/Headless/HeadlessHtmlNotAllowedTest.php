<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\System\Controller\HomeController;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HeadlessHtmlNotAllowedTest extends TestCase
{
    public function testHtmlIsBlockedWhenNotAllowlisted(): void
    {
        $prevHeadless = $_ENV['APP_HEADLESS'] ?? null;
        $prevAllowlist = $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] ?? null;
        $_ENV['APP_HEADLESS'] = 'true';
        $_ENV['APP_HEADLESS_HTML_ALLOWLIST'] = '';

        try {
            $pdo = SecurityTestHelper::createSqlitePdo();
            SecurityTestHelper::seedSettingsTable($pdo);
            SecurityTestHelper::seedPagesTable($pdo);
            SecurityTestHelper::seedMediaTable($pdo);
            SecurityTestHelper::seedMenusTables($pdo);
            $db = SecurityTestHelper::dbManagerFromPdo($pdo);

            $request = new Request('GET', '/', [], [], ['accept' => 'text/html'], '');
            RequestScope::setRequest($request);
            $view = SecurityTestHelper::createView($db, $request, 'default');

            $container = SecurityTestHelper::createContainer($db);
            $controller = new HomeController($view, $container);
            $response = $controller->index($request);

            $this->assertSame(406, $response->getStatus());
            $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
            $payload = json_decode($response->getBody(), true);
            $this->assertSame('E_FORMAT_NOT_ACCEPTABLE', $payload['error']['code'] ?? null);
            $this->assertSame('json', $payload['meta']['format'] ?? null);
            $this->assertArrayHasKey('request_id', $payload['meta'] ?? []);
            $this->assertArrayHasKey('ts', $payload['meta'] ?? []);
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
