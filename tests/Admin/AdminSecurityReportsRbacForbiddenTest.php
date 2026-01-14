<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SecurityReportsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSecurityReportsRbacForbiddenTest extends TestCase
{
    public function testIndexDeniedWithoutPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('GET', '/admin/security-reports');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('E_RBAC_DENIED', $payload['error']['code'] ?? null);
        $this->assertSame('admin.security_reports.index', $payload['meta']['route'] ?? null);
        $this->assertSame('error.rbac_denied', $payload['meta']['error']['key'] ?? null);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        $pdo->exec('CREATE TABLE security_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NULL,
            document_uri TEXT NOT NULL,
            violated_directive TEXT NOT NULL,
            blocked_uri TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            ip TEXT NOT NULL,
            request_id TEXT NULL,
            triaged_at TEXT NULL,
            ignored_at TEXT NULL
        )');
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

    private function createController(\PDO $pdo, Request $request): SecurityReportsController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new SecurityReportsController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
