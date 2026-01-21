<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\ModulesController;
use Laas\Modules\ModuleCatalog;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\View\AssetManager;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminModulesRequestCacheTest extends TestCase
{
    public function testModulesListQueryCachedPerRequest(): void
    {
        RequestScope::reset();

        $pdo = $this->createCountingPdo();
        $this->createBaseSchema($pdo);
        $this->seedModulesManage($pdo, 1);
        $pdo->exec("INSERT INTO modules (name, enabled, version, installed_at, updated_at) VALUES
            ('Pages', 1, '1.0.0', '2026-01-01', '2026-01-01')");

        $request = $this->makeRequest('GET', '/admin/modules');
        RequestScope::setRequest($request);
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);

        $catalog = new ModuleCatalog(SecurityTestHelper::rootPath(), $db);
        $view->share('admin_modules_nav', $catalog->listNav());
        $view->share('admin_modules_nav_sections', $catalog->listNavSections());

        $container = SecurityTestHelper::createContainer($db);
        $controller = new ModulesController($view, null, $container);
        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(1, $pdo->modulesQueryCount);

        RequestScope::setRequest(null);
        RequestScope::reset();
    }

    private function createCountingPdo(): AdminModulesCountingPdo
    {
        $pdo = new AdminModulesCountingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function createBaseSchema(AdminModulesCountingPdo $pdo): void
    {
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE modules (name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, version TEXT NULL, installed_at TEXT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX idx_modules_enabled ON modules (enabled)');
        SecurityTestHelper::seedRbacTables($pdo);
    }

    private function seedModulesManage(AdminModulesCountingPdo $pdo, int $userId): void
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
            $root . '/storage/cache/templates_admin_modules_cache',
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
            $root . '/storage/cache/templates_admin_modules_cache',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}

final class AdminModulesCountingPdo extends PDO
{
    public int $modulesQueryCount = 0;

    public function query(string $statement, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($statement, 'SELECT name, enabled') !== false && stripos($statement, 'FROM modules') !== false) {
            $this->modulesQueryCount++;
        }

        if ($fetchMode === null) {
            return parent::query($statement);
        }

        return parent::query($statement, $fetchMode, ...$fetchModeArgs);
    }
}
