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

final class AdminSecurityReportsShowJsonTest extends TestCase
{
    public function testShowReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 1, 'security_reports.view');
        $this->seedReport($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/security-reports/1');
        $controller = $this->createController($pdo, $request);

        $response = $controller->show($request, ['id' => 1]);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.security_reports.show', $payload['meta']['route'] ?? null);
        $report = $payload['data']['report'] ?? [];
        $this->assertSame(1, $report['id'] ?? null);
        $this->assertSame('csp', $report['type'] ?? null);
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

    private function seedPermission(\PDO $pdo, int $userId, string $permission): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, $permission);
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function seedReport(\PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO security_reports (id, type, status, created_at, updated_at, document_uri, violated_directive, blocked_uri, user_agent, ip, request_id)
             VALUES (:id, :type, :status, :created_at, :updated_at, :document_uri, :violated_directive, :blocked_uri, :user_agent, :ip, :request_id)'
        );
        $stmt->execute([
            'id' => $id,
            'type' => 'csp',
            'status' => 'triaged',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
            'document_uri' => 'https://example.com',
            'violated_directive' => 'script-src',
            'blocked_uri' => 'https://evil.example/script.js',
            'user_agent' => 'Mozilla/5.0',
            'ip' => '203.0.113.10',
            'request_id' => 'req-1',
        ]);
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
        return new SecurityReportsController($view, $db, new SecurityReportsService($db));
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
