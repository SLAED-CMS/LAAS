<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Users\UsersService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\UsersController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminUsersIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        $this->seedUsersManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/users');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpyUsersService($db);
        $controller = new UsersController($view, $db, $service);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->listCalled);
        $this->assertTrue($service->rolesCalled);
        $this->assertTrue($service->countCalled);
    }

    private function seedUsersManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'users.manage');
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

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}

final class SpyUsersService extends UsersService
{
    public bool $listCalled = false;
    public bool $rolesCalled = false;
    public bool $countCalled = false;

    public function list(array $filters = []): array
    {
        $this->listCalled = true;
        return [[
            'id' => 1,
            'username' => 'admin',
            'email' => 'admin@example.com',
            'status' => 1,
            'active' => true,
            'created_at' => '2026-01-01 00:00:00',
        ]];
    }

    public function rolesForUsers(array $userIds): array
    {
        $this->rolesCalled = true;
        return [1 => ['admin']];
    }

    public function count(array $filters = []): int
    {
        $this->countCalled = true;
        return 1;
    }
}
