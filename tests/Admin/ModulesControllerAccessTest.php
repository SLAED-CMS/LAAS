<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class ModulesControllerAccessTest extends TestCase
{
    public function testIndexRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('GET', '/admin/modules', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testIndexAllowsWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/modules', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
    }

    public function testToggleRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('POST', '/admin/modules/toggle', [
            'name' => 'Changelog',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggle($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testToggleLogsAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);
        $pdo->exec("INSERT INTO modules (name, enabled, version, installed_at, updated_at) VALUES ('Changelog', 0, '0.1.0', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $request = $this->makeRequest('POST', '/admin/modules/toggle', [
            'name' => 'Changelog',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggle($request);

        $this->assertSame(200, $response->getStatus());
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'modules.toggle'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-01-01 00:00:00');
        }
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE modules (name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, version TEXT NULL, installed_at TEXT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX idx_modules_enabled ON modules (enabled)');
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
        return $pdo;
    }

    private function seedModulesManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.modules.manage');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path, array $post, bool $htmx = false): Request
    {
        $headers = $htmx ? ['hx-request' => 'true'] : [];
        $request = new Request($method, $path, [], $post, $headers, '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): ModulesController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new ModulesController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
