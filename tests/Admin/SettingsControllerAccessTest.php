<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Settings\SettingsService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SettingsController;
use Laas\View\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

#[Group('security')]
final class SettingsControllerAccessTest extends TestCase
{
    public function testIndexRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('GET', '/admin/settings', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testIndexAllowsWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedSettingsManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/settings', []);
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
    }

    public function testSaveRequiresPermission(): void
    {
        $pdo = $this->createBaseSchema();
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');

        $request = $this->makeRequest('POST', '/admin/settings', $this->validPayload(), true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->save($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testSaveLogsAuditWithPermission(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedSettingsManage($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/settings', $this->validPayload(), true);
        $controller = $this->createController($pdo, $request);

        $response = $controller->save($request);

        $this->assertSame(200, $response->getStatus());
        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.save'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
        return $pdo;
    }

    private function seedSettingsManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.settings.manage');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function validPayload(): array
    {
        return [
            'site_name' => 'LAAS CMS',
            'default_locale' => 'en',
            'theme' => 'default',
            'api_token_issue_mode' => 'admin',
        ];
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

    private function createController(\PDO $pdo, Request $request): SettingsController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new SettingsController($view, $db, new SettingsService($db));
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
