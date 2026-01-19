<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Menus\MenusService;
use Laas\Http\Request;
use Laas\Modules\Menu\Controller\AdminMenusController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminMenusIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMenusTables($pdo);
        $this->seedMenuAccess($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/menus');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpyMenusService($db);
        $controller = new AdminMenusController($view, $db, $service);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->findByNameCalled);
        $this->assertTrue($service->loadItemsCalled);
    }

    private function seedMenuAccess(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'menus.edit');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], [], '');
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

final class SpyMenusService extends MenusService
{
    public bool $findByNameCalled = false;
    public bool $loadItemsCalled = false;

    public function findByName(string $name): ?array
    {
        $this->findByNameCalled = true;
        return [
            'id' => 1,
            'name' => 'main',
            'title' => 'Main',
        ];
    }

    public function loadItems(int $menuId, bool $enabledOnly = false): array
    {
        $this->loadItemsCalled = true;
        return [];
    }
}
