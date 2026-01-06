<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\UsersController;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class UsersControllerAccessTest extends TestCase
{
    public function testIndexRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('GET', '/admin/users', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testIndexAllowsWithUsersManage(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/users', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
    }

    public function testToggleStatusRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/status', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggleStatus($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testToggleStatusLogsAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/status', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggleStatus($request);

        $this->assertSame(200, $response->getStatus());
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'users.status.updated'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testToggleAdminRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/admin', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggleAdmin($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testToggleAdminLogsAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);
        SecurityTestHelper::assignRole($pdo, 2, 1);

        $request = $this->makeRequest('POST', '/admin/users/admin', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->toggleAdmin($request);

        $this->assertSame(200, $response->getStatus());
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'rbac.user.roles.updated'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testChangePasswordRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/password', [
            'user_id' => '2',
            'password' => 'newpass1',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->changePassword($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testChangePasswordUpdatesHashAndAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);
        SecurityTestHelper::insertUser($pdo, 2, 'user', password_hash('oldpass', PASSWORD_DEFAULT), 1);

        $request = $this->makeRequest('POST', '/admin/users/password', [
            'user_id' => '2',
            'password' => 'newpass1',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->changePassword($request);

        $this->assertSame(200, $response->getStatus());
        $row = $pdo->query('SELECT password_hash FROM users WHERE id = 2')->fetch();
        $this->assertTrue(password_verify('newpass1', (string) ($row['password_hash'] ?? '')));
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'users.password.changed'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testDeleteRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/delete', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->delete($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testDeleteRemovesUserAndLogsAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedUsersManage($pdo, 1);
        SecurityTestHelper::insertUser($pdo, 2, 'user', 'hash', 1);

        $request = $this->makeRequest('POST', '/admin/users/delete', [
            'user_id' => '2',
        ], true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->delete($request);

        $this->assertSame(200, $response->getStatus());
        $row = $pdo->query('SELECT id FROM users WHERE id = 2')->fetch();
        $this->assertFalse((bool) $row);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'users.deleted'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-01-01 00:00:00');
        }
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
        return $pdo;
    }

    private function seedUsersManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'users.manage');
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

    private function createController(\PDO $pdo, Request $request): UsersController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new UsersController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
