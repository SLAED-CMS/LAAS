<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminModulesToggleJsonTest extends TestCase
{
    public function testProtectedModuleReturnsError(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/modules/toggle', [
            'name' => 'System',
        ]);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggle($request);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('protected_module', $payload['error'] ?? null);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.modules.toggle', $payload['meta']['route'] ?? null);
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

    private function makeRequest(string $method, string $path, array $post): Request
    {
        $request = new Request($method, $path, [], $post, ['accept' => 'application/json'], '');
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
