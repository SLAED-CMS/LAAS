<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\Modules\ModuleCatalog;
use Laas\Settings\SettingsProvider;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminModulesPageTest extends TestCase
{
    public function testIndexRendersModulesList(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedModulesManage($pdo, 1);

        $request = $this->makeRequest('GET', '/admin/modules');
        $controller = $this->createController($pdo, $request);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $body = $response->getBody();
        $this->assertStringContainsString('Modules', $body);
        $this->assertStringContainsString('System', $body);
        $this->assertStringContainsString('name="q"', $body);
        $this->assertStringContainsString('name="status"', $body);
        $this->assertStringContainsString('name="type"', $body);
        $this->assertStringContainsString('bi bi-robot', $body);
        $this->assertStringContainsString('href="/admin/ai">Open</a>', $body);
        $this->assertStringContainsString('href="/admin/pages/new">New</a>', $body);
        $this->assertStringContainsString('Details', $body);
        $this->assertStringContainsString('href="/admin/modules#module-users"', $body);
        $this->assertStringContainsString('hx-get="/admin/modules/details?module=users"', $body);
        $this->assertStringContainsString('data-details-btn="1"', $body);
        $this->assertStringContainsString('data-module-id="users"', $body);
        $this->assertStringContainsString('data-details-target="#module-details-users"', $body);
        $this->assertStringContainsString('data-details-row="#module-details-row-users"', $body);
        $this->assertStringContainsString('id="module-details-row-users"', $body);
        $this->assertStringContainsString('id="module-details-users"', $body);
        $this->assertStringContainsString('href="/admin/modules#module-system"', $body);
        $this->assertStringContainsString('href="/admin/modules#module-api"', $body);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $body);
        $this->assertStringContainsString('title="Open"', $body);
        $this->assertStringNotContainsString('&mdash;', $body);
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

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], [], '');
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
            $root . '/storage/cache/templates_admin_modules',
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
            $root . '/storage/cache/templates_admin_modules',
            $db
        );
        $view->setRequest($request);
        $catalog = new ModuleCatalog($root, $db);
        $view->share('admin_modules_nav', $catalog->listNav());
        $view->share('admin_modules_nav_sections', $catalog->listNavSections());

        return $view;
    }
}
