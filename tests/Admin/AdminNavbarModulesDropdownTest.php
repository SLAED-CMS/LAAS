<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
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

final class AdminNavbarModulesDropdownTest extends TestCase
{
    public function testModulesDropdownRendersSearchSectionsAndActions(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` VARCHAR(255) UNIQUE, `value` TEXT NULL, `type` VARCHAR(20) NULL, updated_at DATETIME NULL)');
        $pdo->exec('CREATE TABLE modules (name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, version TEXT NULL, installed_at TEXT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX idx_modules_enabled ON modules (enabled)');

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db);

        $catalog = new ModuleCatalog(SecurityTestHelper::rootPath(), $db);
        $view->share('admin_modules_nav', $catalog->listNav());
        $view->share('admin_modules_nav_sections', $catalog->listNavSections());

        $response = $view->render('partials/header.html', [], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);

        $body = $response->getBody();
        $this->assertStringContainsString('id="modules-nav-q"', $body);
        $this->assertStringContainsString('data-modules-nav-section="core"', $body);
        $this->assertStringContainsString('data-modules-nav-section="content"', $body);
        $this->assertStringContainsString('data-modules-nav-section="system"', $body);
        $this->assertStringContainsString('data-modules-nav-section="dev"', $body);
        $this->assertStringContainsString('data-modules-nav-section="demo"', $body);
        $this->assertStringContainsString('bi bi-file-earmark-text', $body);
        $this->assertStringContainsString('href="/admin/pages"', $body);
        $this->assertStringContainsString('nav-modules-badge', $body);
        $this->assertStringContainsString('data-bs-toggle="tooltip"', $body);
        $this->assertStringContainsString('title="Open"', $body);
    }

    private function createView(DatabaseManager $db): View
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
            $root . '/storage/cache/templates_admin_navbar',
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
            $root . '/storage/cache/templates_admin_navbar',
            $db
        );

        $session = new InMemorySession();
        $session->start();
        $request = new Request('GET', '/admin', [], [], [], '', $session);
        $view->setRequest($request);

        return $view;
    }
}
