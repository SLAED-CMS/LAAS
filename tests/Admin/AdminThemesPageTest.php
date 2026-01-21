<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Core\Container\Container;
use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ThemesController;
use Laas\View\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminThemesPageTest extends TestCase
{
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
        parent::tearDown();
    }
    public function testIndexRendersThemeInfo(): void
    {
        $db = $this->createDatabase();
        $this->seedAdminAccess($db->pdo(), 1);

        $request = $this->makeRequest('GET', '/admin/themes', 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = $this->makeContainer($db);
        $controller = new ThemesController($view, null, $container);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('Theme Inspector', $body);
        $this->assertStringContainsString('Capabilities', $body);
        $this->assertStringContainsString('headless', $body);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function makeContainer(DatabaseManager $db): Container
    {
        $rootPath = SecurityTestHelper::rootPath();
        $container = new Container();
        $container->singleton(FeatureFlagsInterface::class, static function (): FeatureFlagsInterface {
            return new FeatureFlags([
                FeatureFlagsInterface::DEVTOOLS_THEME_INSPECTOR => true,
            ]);
        });
        $container->singleton(RbacServiceInterface::class, function () use ($db): RbacServiceInterface {
            return new RbacService($db);
        });
        $container->singleton(ThemeManager::class, function () use ($rootPath): ThemeManager {
            return new ThemeManager($rootPath . '/themes', 'admin', null);
        });
        return $container;
    }

    private function seedAdminAccess(PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.access');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
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
