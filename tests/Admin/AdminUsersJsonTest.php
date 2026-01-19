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

final class AdminUsersJsonTest extends TestCase
{
    public function testIndexReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/users');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.users.index', $payload['meta']['route'] ?? null);
        $this->assertIsArray($payload['data']['items'] ?? null);
        $this->assertIsArray($payload['data']['pagination'] ?? null);
        $items = $payload['data']['items'] ?? [];
        $this->assertNotEmpty($items);
        $first = $items[0] ?? [];
        $this->assertSame('admin', $first['username'] ?? null);
        $this->assertTrue($first['active'] ?? false);
        $this->assertContains('admin', $first['roles'] ?? []);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        return $pdo;
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

    private function createController(\PDO $pdo, Request $request): UsersController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new UsersController($view, $db, new UsersService($db));
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
