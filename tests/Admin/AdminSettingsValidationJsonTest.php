<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SettingsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSettingsValidationJsonTest extends TestCase
{
    public function testSaveValidationReturnsJsonErrors(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedSettingsManage($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/settings', [
            'site_name' => '',
            'default_locale' => 'en',
            'theme' => 'default',
            'api_token_issue_mode' => 'admin',
        ]);
        $controller = $this->createController($pdo, $request);

        $response = $controller->save($request);

        $this->assertSame(422, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('validation_failed', $payload['error'] ?? null);
        $this->assertArrayHasKey('fields', $payload);
        $this->assertArrayHasKey('site_name', $payload['fields']);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        SecurityTestHelper::seedRbacTables($pdo);
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

    private function makeRequest(string $method, string $path, array $post): Request
    {
        $request = new Request($method, $path, [], $post, ['accept' => 'application/json'], '');
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
        return new SettingsController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }
}
