<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\AdminAiController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminAiDevAutopilotTest extends TestCase
{
    public function testDevAutopilotPreview(): void
    {
        $request = $this->makeRequest('POST', '/admin/ai/dev-autopilot', [
            'module_name' => 'AutoX',
        ]);
        $controller = $this->createController($request);

        $response = $controller->devAutopilot($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('Dev Autopilot Preview', $body);
        $this->assertStringContainsString('storage/sandbox/modules/', $body);
        $this->assertStringContainsString('proposal_json', $body);
        $this->assertStringNotContainsString('ai:proposal:apply', $body);
    }

    private function makeRequest(string $method, string $path, array $post): Request
    {
        $request = new Request($method, $path, [], $post, ['hx-request' => 'true'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(Request $request): AdminAiController
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new AdminAiController($view);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
