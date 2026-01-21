<?php
declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Domain\AdminSearch\AdminSearchService;
use Laas\Domain\Media\MediaService;
use Laas\Domain\Menus\MenusService;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Users\UsersService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\AdminSearchController;
use Laas\Modules\ModuleCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSearchPaletteControllerTest extends TestCase
{
    public function testPaletteReturnsJsonAndOrderedGroups(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedPagesTable($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        SecurityTestHelper::seedMenusTables($pdo);

        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.access');
        SecurityTestHelper::insertPermission($pdo, 2, 'pages.edit');
        SecurityTestHelper::grantPermission($pdo, 1, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 2);
        SecurityTestHelper::assignRole($pdo, 1, 1);

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $request = $this->makeRequest('GET', '/admin/search/palette', ['q' => 'page']);
        $view = SecurityTestHelper::createView($db, $request, 'admin');

        $service = $this->createService($db);
        $container = new Container();
        $container->singleton(RbacServiceInterface::class, function () use ($db): RbacServiceInterface {
            return new RbacService($db);
        });

        $controller = new AdminSearchController($view, $service, $container);
        $response = $controller->palette($request);

        $this->assertSame(200, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertIsArray($payload['groups'] ?? null);
        $this->assertSame('commands', $payload['groups'][0]['key'] ?? null);

        $commands = $payload['groups'][0]['items'] ?? [];
        $this->assertNotEmpty($commands);
        $this->assertSame('/admin/pages/new', $commands[0]['url'] ?? null);
    }

    private function makeRequest(string $method, string $path, array $query): Request
    {
        $request = new Request($method, $path, $query, [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createService(DatabaseManager $db): AdminSearchService
    {
        $rootPath = SecurityTestHelper::rootPath();
        $pages = new PagesService($db);
        $media = new MediaService($db, [], $rootPath);
        $users = new UsersService($db);
        $menus = new MenusService($db);
        $modules = new ModuleCatalog($rootPath, null, null);

        return new AdminSearchService($pages, $media, $users, $menus, $modules);
    }
}
