<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Rbac\RbacService;
use Laas\Http\Request;
use Laas\Modules\Pages\Controller\AdminPagesController;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminPagesBlocksStudioRenderTest extends TestCase
{
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
    }

    public function testBlocksStudioShowsWhenAllowed(): void
    {
        $_ENV['APP_DEBUG'] = 'true';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('data-blocks-studio="1"', $response->getBody());
        $this->assertStringContainsString('data-blocks-studio-list="1"', $response->getBody());
    }

    public function testBlocksStudioHiddenWhenNotAllowed(): void
    {
        $_ENV['APP_DEBUG'] = 'false';
        $db = $this->createDatabase();
        $this->seedEditor($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/pages/new', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $pages = new PagesService($db);
        $rbac = new RbacService($db);
        $controller = new AdminPagesController($view, $pages, $pages, null, $rbac);

        $response = $controller->createForm($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringNotContainsString('data-blocks-studio="1"', $response->getBody());
        $this->assertStringNotContainsString('name="blocks_json"', $response->getBody());
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedPagesTable($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        return $db;
    }

    private function seedEditor(PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'editor', 'hash');
        SecurityTestHelper::insertRole($pdo, 2, 'editor');
        SecurityTestHelper::insertPermission($pdo, 1, 'pages.edit');
        SecurityTestHelper::assignRole($pdo, $userId, 2);
        SecurityTestHelper::grantPermission($pdo, 2, 1);
    }

    private function makeRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path, [], [], [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }
}
