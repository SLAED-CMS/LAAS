<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SecurityReportsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSecurityReportsIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesServiceForData(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        $this->seedPermission($pdo, 1, 'security_reports.view');

        $request = $this->makeRequest('GET', '/admin/security-reports');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpySecurityReportsService($db);
        $controller = new SecurityReportsController($view, $db, $service);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->countCalled);
        $this->assertTrue($service->listCalled);
        $this->assertSame(100, $service->lastListFilters['limit'] ?? null);
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
        $request = new Request($method, $path, [], [], ['accept' => 'application/json'], '');
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

final class SpySecurityReportsService extends SecurityReportsService
{
    public bool $listCalled = false;
    public bool $countCalled = false;
    public array $lastListFilters = [];

    public function list(array $filters = []): array
    {
        $this->listCalled = true;
        $this->lastListFilters = $filters;

        return [[
            'id' => 1,
            'type' => 'csp',
            'status' => 'new',
            'document_uri' => 'https://example.com',
            'violated_directive' => 'script-src',
            'blocked_uri' => 'https://example.com/blocked',
            'user_agent' => 'TestAgent',
            'ip' => '203.0.113.10',
            'request_id' => 'req-1',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'triaged_at' => null,
            'ignored_at' => null,
        ]];
    }

    public function count(array $filters = []): int
    {
        $this->countCalled = true;
        return 1;
    }
}
