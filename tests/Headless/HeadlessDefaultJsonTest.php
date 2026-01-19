<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Modules\System\Controller\HomeController;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

final class HeadlessDefaultJsonTest extends TestCase
{
    public function testHeadlessDefaultsToJsonEnvelope(): void
    {
        $prev = $_ENV['APP_HEADLESS'] ?? null;
        $_ENV['APP_HEADLESS'] = 'true';

        try {
            $pdo = SecurityTestHelper::createSqlitePdo();
            SecurityTestHelper::seedSettingsTable($pdo);
            SecurityTestHelper::seedPagesTable($pdo);
            SecurityTestHelper::seedMediaTable($pdo);
            SecurityTestHelper::seedMenusTables($pdo);
            $db = SecurityTestHelper::dbManagerFromPdo($pdo);

            $request = new Request('GET', '/', [], [], [], '');
            RequestScope::setRequest($request);
            $view = SecurityTestHelper::createView($db, $request, 'default');

            $controller = new HomeController($view, $db);
            $response = $controller->index($request);

            $this->assertSame(200, $response->getStatus());
            $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
            $payload = json_decode($response->getBody(), true);
            $this->assertSame('json', $payload['meta']['format'] ?? null);
            $this->assertArrayHasKey('data', $payload);
        } finally {
            RequestScope::reset();
            RequestScope::setRequest(null);
            if ($prev === null) {
                unset($_ENV['APP_HEADLESS']);
            } else {
                $_ENV['APP_HEADLESS'] = $prev;
            }
        }
    }
}
