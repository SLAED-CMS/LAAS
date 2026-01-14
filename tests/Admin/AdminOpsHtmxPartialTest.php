<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\OpsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminOpsHtmxPartialTest extends TestCase
{
    public function testIndexReturnsPartialForHtmx(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 1, 'ops.view');

        $request = $this->makeRequest('GET', '/admin/ops');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-ops-section="health"', $response->getBody());
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        return $pdo;
    }

    private function seedPermission(\PDO $pdo, int $userId, string $permission): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, $permission);
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], ['hx-request' => 'true'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): OpsController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new OpsController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
