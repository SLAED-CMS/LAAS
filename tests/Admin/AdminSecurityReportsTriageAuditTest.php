<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SecurityReportsController;
use Laas\Support\RequestScope;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class AdminSecurityReportsTriageAuditTest extends TestCase
{
    public function testTriageWritesAudit(): void
    {
        RequestScope::reset();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.12';

        $pdo = $this->createBaseSchema();
        $this->seedPermission($pdo, 1, 'security_reports.manage');
        $this->seedReport($pdo, 1);

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $request = $this->makeRequest('POST', '/admin/security-reports/1/triage');
        RequestScope::setRequest($request);
        RequestScope::set('db.manager', $db);

        $controller = $this->createController($db, $request);
        $response = $controller->triage($request, ['id' => 1]);
        RequestScope::setRequest(null);

        $this->assertSame(200, $response->getStatus());

        $rows = $pdo->query('SELECT action, context FROM audit_logs ORDER BY id ASC')->fetchAll();
        $actions = array_map(static fn(array $row): string => (string) ($row['action'] ?? ''), $rows ?: []);
        $this->assertContains('security_report.triaged', $actions);

        $contexts = array_map(static function (array $row): array {
            $raw = (string) ($row['context'] ?? '');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }, $rows ?: []);
        $actorIds = array_map(static fn(array $ctx): ?int => isset($ctx['actor_user_id']) ? (int) $ctx['actor_user_id'] : null, $contexts);
        $actorNames = array_map(static fn(array $ctx): ?string => isset($ctx['actor_username']) ? (string) $ctx['actor_username'] : null, $contexts);
        $this->assertContains(1, $actorIds);
        $this->assertContains('admin', $actorNames);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
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
            'status' => 'new',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
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

    private function createController(DatabaseManager $db, Request $request): SecurityReportsController
    {
        $view = $this->createView($db, $request);
        return new SecurityReportsController($view, $db, new SecurityReportsService($db));
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
