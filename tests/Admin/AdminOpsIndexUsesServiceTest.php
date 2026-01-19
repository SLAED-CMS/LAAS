<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Ops\OpsService;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\OpsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminOpsIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        $this->seedPermission($pdo, 1, 'ops.view');

        $request = $this->makeRequest('GET', '/admin/ops');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpyOpsService($db, $this->config(), SecurityTestHelper::rootPath(), new SecurityReportsService($db));
        $controller = new OpsController($view, $db, $service);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->overviewCalled);
        $this->assertTrue($service->viewDataCalled);
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

    private function config(): array
    {
        return [
            'app' => [
                'env' => 'test',
                'debug' => false,
                'read_only' => false,
                'headless_mode' => false,
            ],
            'security' => [
                'session' => ['driver' => 'native'],
            ],
            'storage' => [
                'default' => 'local',
            ],
            'media' => [],
            'perf' => [],
        ];
    }
}

final class SpyOpsService extends OpsService
{
    public bool $overviewCalled = false;
    public bool $viewDataCalled = false;

    public function overview(bool $isHttps): array
    {
        $this->overviewCalled = true;
        return parent::overview($isHttps);
    }

    public function viewData(array $snapshot, callable $translate): array
    {
        $this->viewDataCalled = true;
        return parent::viewData($snapshot, $translate);
    }
}
