<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\AdminAiController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminAiSaveProposalTest extends TestCase
{
    public function testSaveProposalStoresAndReturnsHint(): void
    {
        $proposal = [
            'id' => 'demo-proposal',
            'created_at' => gmdate(DATE_ATOM),
            'kind' => 'demo',
            'summary' => 'Test proposal',
            'file_changes' => [],
            'entity_changes' => [],
            'warnings' => [],
            'confidence' => 0.2,
            'risk' => 'low',
        ];

        $request = $this->makeRequest('POST', '/admin/ai/save-proposal', [
            'proposal_json' => json_encode($proposal, JSON_UNESCAPED_SLASHES),
        ]);
        $controller = $this->createController($request);

        $response = $controller->saveProposal($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('ai:proposal:apply', $response->getBody());
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
