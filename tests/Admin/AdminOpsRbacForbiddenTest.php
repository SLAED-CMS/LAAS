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

final class AdminOpsRbacForbiddenTest extends TestCase
{
    public function testIndexDeniedWithoutPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('GET', '/admin/ops');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
        $this->assertSame('admin.ops.index', $payload['meta']['route'] ?? null);
        $this->assertSame('error.rbac_denied', $payload['meta']['error']['key'] ?? null);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        return $pdo;
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

    private function createController(\PDO $pdo, Request $request): OpsController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = $this->createOpsService($db);
        $container = SecurityTestHelper::createContainer($db);
        return new OpsController($view, $service, $container);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }

    private function createOpsService(DatabaseManager $db): OpsService
    {
        $config = [
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
        $reports = new SecurityReportsService($db);

        return new OpsService($db, $config, SecurityTestHelper::rootPath(), $reports);
    }
}
