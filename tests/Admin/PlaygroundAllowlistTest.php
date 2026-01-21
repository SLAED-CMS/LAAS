<?php
declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Core\FeatureFlags;
use Laas\Core\FeatureFlagsInterface;
use Laas\Domain\Rbac\RbacService;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\HeadlessPlaygroundController;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class PlaygroundAllowlistTest extends TestCase
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
    public function testExternalUrlRejected(): void
    {
        $db = $this->createDatabase();
        $this->seedAdminAccess($db->pdo(), 1);

        $request = $this->makeRequest('/admin/headless-playground/fetch', ['url' => 'https://example.com/'], 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $view->share('admin_features', $this->adminFeatures());
        $container = $this->makeContainer($db);
        $controller = new HeadlessPlaygroundController($view, null, $container);

        $response = $controller->fetch($request);

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('External URLs are not allowed', $response->getBody());
    }

    public function testNonApiV2Rejected(): void
    {
        $db = $this->createDatabase();
        $this->seedAdminAccess($db->pdo(), 1);

        $request = $this->makeRequest('/admin/headless-playground/fetch', ['url' => '/admin/pages'], 1);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $view->share('admin_features', $this->adminFeatures());
        $container = $this->makeContainer($db);
        $controller = new HeadlessPlaygroundController($view, null, $container);

        $response = $controller->fetch($request);

        $this->assertSame(400, $response->getStatus());
        $this->assertStringContainsString('Only /api/v2 endpoints are allowed', $response->getBody());
    }

    private function createDatabase(): \Laas\Database\DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function seedAdminAccess(PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.access');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $path, array $query, int $userId): Request
    {
        $request = new Request('GET', $path, $query, [], [], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }

    private function makeContainer(\Laas\Database\DatabaseManager $db): Container
    {
        $container = new Container();
        $container->singleton(FeatureFlagsInterface::class, static function (): FeatureFlagsInterface {
            return new FeatureFlags([
                FeatureFlagsInterface::DEVTOOLS_HEADLESS_PLAYGROUND => true,
            ]);
        });
        $container->singleton(RbacServiceInterface::class, function () use ($db): RbacServiceInterface {
            return new RbacService($db);
        });
        return $container;
    }

    /**
     * @return array<string, bool>
     */
    private function adminFeatures(): array
    {
        return [
            'palette' => true,
            'blocks_studio' => true,
            'theme_inspector' => true,
            'headless_playground' => true,
        ];
    }
}
