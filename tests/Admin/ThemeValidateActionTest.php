<?php
declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ThemesController;
use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class ThemeValidateActionTest extends TestCase
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

    public function testValidateForbiddenWhenNotDebug(): void
    {
        $_ENV['APP_DEBUG'] = 'false';

        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.access');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $request = $this->makeRequest('POST', '/admin/themes/validate', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = $this->makeContainer($db);
        $controller = new ThemesController($view, null, $container);

        $response = $controller->validate($request);

        $this->assertSame(403, $response->getStatus());
    }

    private function makeRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path, [], ['_token' => 'x'], [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }

    private function makeContainer(\Laas\Database\DatabaseManager $db): Container
    {
        $rootPath = SecurityTestHelper::rootPath();
        $container = new Container();
        $container->singleton(RbacServiceInterface::class, function () use ($db): RbacServiceInterface {
            return new RbacService($db);
        });
        $container->singleton(ThemeManager::class, function () use ($rootPath): ThemeManager {
            return new ThemeManager($rootPath . '/themes', 'admin', null);
        });
        return $container;
    }
}
