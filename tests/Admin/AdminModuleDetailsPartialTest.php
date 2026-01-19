<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminModuleDetailsPartialTest extends TestCase
{
    public function testDetailsRendersAiModule(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/modules/details', ['module' => 'ai']);
        $controller = $this->createController($pdo, $request);

        $response = $controller->details($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('text/html; charset=utf-8', $response->getHeader('Content-Type'));
        $this->assertSame('no-store', $response->getHeader('Cache-Control'));
        $body = $response->getBody();
        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('AI', $body);
        $this->assertStringContainsString('/admin/ai', $body);
        $this->assertStringContainsString('data-details-close="1"', $body);
        $this->assertStringContainsString('data-module-id="ai"', $body);
    }

    public function testDetailsShowsNoAdminUiForInternalModule(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/modules/details', ['module' => 'system']);
        $controller = $this->createController($pdo, $request);

        $response = $controller->details($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('No admin UI', $response->getBody());
    }

    public function testDetailsRejectsUnknownModule(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/modules/details', ['module' => 'missing-module']);
        $controller = $this->createController($pdo, $request);

        $response = $controller->details($request);

        $this->assertContains($response->getStatus(), [400, 404]);
        $this->assertStringContainsString('Module', $response->getBody());
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
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

    /**
     * @param array<string, string> $query
     */
    private function makeRequest(string $method, string $path, array $query): Request
    {
        $request = new Request($method, $path, $query, [], [], '');
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
        $root = SecurityTestHelper::rootPath();
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'admin',
        ], ['site_name', 'default_locale', 'theme']);
        $themeManager = new ThemeManager($root . '/themes', 'admin', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $root . '/storage/cache/templates_admin_module_details',
            true
        );
        $translator = new Translator($root, 'admin', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => true],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $root . '/storage/cache/templates_admin_module_details',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}
