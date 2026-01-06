<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Menu\Controller\AdminMenusController;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class AdminMenusUrlValidationTest extends TestCase
{
    public function testSaveItemRejectsUnsafeUrl(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedMenuAccess($pdo, 1);
        $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $request = $this->makeRequest('POST', '/admin/menus/item/save', [
            'label' => 'Bad',
            'url' => 'javascript:alert(1)',
            'enabled' => '1',
            'is_external' => '1',
            'sort_order' => '0',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->saveItem($request);

        $this->assertSame(422, $response->getStatus());
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMenusTables($pdo);
        return $pdo;
    }

    private function seedMenuAccess(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'menus.edit');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path, array $post, bool $htmx = false): Request
    {
        $headers = $htmx ? ['hx-request' => 'true'] : [];
        $request = new Request($method, $path, [], $post, $headers, '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): AdminMenusController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new AdminMenusController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
