<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\System\Controller\HomeController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class HomeControllerTest extends TestCase
{
    private string $rootPath;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
        $this->envBackup = $_ENV;
        $this->clearTemplateCache();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
    }

    public function testPageLoads(): void
    {
        $db = $this->createDatabase();
        $request = new Request('GET', '/', [], [], [], '', new InMemorySession());
        $view = $this->createView($db, $request, true);
        $controller = new HomeController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('System', $body);
        $this->assertStringContainsString('Pages', $body);
        $this->assertStringContainsString('Media', $body);
        $this->assertStringContainsString('Menus', $body);
        $this->assertStringContainsString('Search', $body);
        $this->assertStringContainsString('Auth / RBAC', $body);
        $this->assertStringContainsString('Features', $body);
    }

    public function testAuditBlockHiddenWithoutPermission(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'user', 'user@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'member', 'Member', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);

        $request = new Request('GET', '/', [], [], [], '', $session);
        $view = $this->createView($db, $request, true);
        $controller = new HomeController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('No audit events.', $body);
        $this->assertStringNotContainsString('<th>Action</th>', $body);
    }

    public function testPerfBlockHiddenWhenNotDebug(): void
    {
        $_ENV['APP_DEBUG'] = 'false';

        $db = $this->createDatabase();
        $request = new Request('GET', '/', [], [], [], '', new InMemorySession());
        $view = $this->createView($db, $request, false);
        $controller = new HomeController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('Performance (debug)', $body);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, content TEXT, status TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE media_files (id INTEGER PRIMARY KEY AUTOINCREMENT, original_name TEXT, mime_type TEXT, disk_path TEXT, size_bytes INTEGER, created_at TEXT, uploaded_by INTEGER, is_public INTEGER)');
        $pdo->exec('CREATE TABLE menus (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER, label TEXT, url TEXT, sort_order INTEGER, enabled INTEGER, is_external INTEGER)');
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, context TEXT, ip_address TEXT, created_at TEXT)');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, updated_at) VALUES ('Welcome', 'welcome', 'Hello world', 'published', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO media_files (original_name, mime_type, disk_path, size_bytes, created_at, is_public) VALUES ('logo.png', 'image/png', 'uploads/2026/01/logo.png', 10, '2026-01-01 00:00:00', 0)");
        $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external) VALUES (1, 'Home', '/', 1, 1, 0)");
        $pdo->exec("INSERT INTO audit_logs (user_id, action, entity, entity_id, context, ip_address, created_at) VALUES (1, 'system.boot', 'system', 1, NULL, '127.0.0.1', '2026-01-01 00:00:00')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createView(DatabaseManager $db, Request $request, bool $debug): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'default',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($this->rootPath . '/themes', 'default', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            $debug
        );
        $translator = new Translator($this->rootPath, 'default', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => $debug],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }

    private function clearTemplateCache(): void
    {
        $cacheRoot = $this->rootPath . '/storage/cache/templates';
        if (!is_dir($cacheRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }
}
