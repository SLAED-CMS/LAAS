<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\OpsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminOpsJsonTest extends TestCase
{
    public function testIndexReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 1, 'ops.view');

        $request = $this->makeRequest('GET', '/admin/ops');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.ops.index', $payload['meta']['route'] ?? null);
        $data = $payload['data'] ?? [];
        $this->assertIsArray($data['health'] ?? null);
        $this->assertIsArray($data['sessions'] ?? null);
        $this->assertIsArray($data['backups'] ?? null);
        $this->assertIsArray($data['performance'] ?? null);
        $this->assertIsArray($data['cache'] ?? null);
        $this->assertIsArray($data['security'] ?? null);
        $this->assertIsArray($data['preflight'] ?? null);
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
        $request = new Request($method, $path, [], [], ['accept' => 'application/json'], '');
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
