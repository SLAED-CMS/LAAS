<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Settings\SettingsService;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\SettingsController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminSettingsIndexUsesServiceTest extends TestCase
{
    public function testIndexUsesService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        SecurityTestHelper::seedRbacTables($pdo);
        $this->seedSettingsManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/settings');
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        $service = new SpySettingsService($db);
        $container = SecurityTestHelper::createContainer($db);
        $controller = new SettingsController($view, $service, $service, $container);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($service->settingsWithSourcesCalled);
        $this->assertTrue($service->defaultSettingsCalled);
    }

    private function seedSettingsManage(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'admin.settings.manage');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], [], '');
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

final class SpySettingsService extends SettingsService
{
    public bool $settingsWithSourcesCalled = false;
    public bool $defaultSettingsCalled = false;

    public function settingsWithSources(): array
    {
        $this->settingsWithSourcesCalled = true;
        return [
            'settings' => [
                'site_name' => 'LAAS CMS',
                'default_locale' => 'en',
                'theme' => 'default',
                'api_token_issue_mode' => 'admin',
            ],
            'sources' => [
                'site_name' => 'CONFIG',
                'default_locale' => 'CONFIG',
                'theme' => 'CONFIG',
                'api_token_issue_mode' => 'CONFIG',
            ],
        ];
    }

    public function defaultSettings(): array
    {
        $this->defaultSettingsCalled = true;
        return [
            'site_name' => 'LAAS CMS',
            'default_locale' => 'en',
            'theme' => 'default',
            'api_token_issue_mode' => 'admin',
        ];
    }
}
