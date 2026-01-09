<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\AdminSearchController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class AdminSearchControllerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testControllerHidesScopesWithoutPermission(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'admin', 'admin@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'media.view', 'Media view', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        $pdo->exec("INSERT INTO pages (id, title, slug, status, content, updated_at) VALUES (1, 'test page', 'test-page', 'draft', 'x', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO media_files (id, original_name, mime_type, disk_path, size_bytes, created_at) VALUES (1, 'test file', 'image/png', 'uploads/x.png', 10, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (id, username, email) VALUES (2, 'testuser', 'user@example.com')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $session = $this->buildSession(1);
        $request = new Request('GET', '/admin/search', ['q' => 'test'], [], ['hx-request' => 'true'], '');
        $request->setSession($session);
        $view = $this->createView($db, $request);
        $controller = new AdminSearchController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringContainsString('Media', $body);
        $this->assertStringNotContainsString('Pages', $body);
        $this->assertStringNotContainsString('Users', $body);
    }

    public function testQueryTooShortReturns422(): void
    {
        $db = $this->createDatabase();
        $request = new Request('GET', '/admin/search', ['q' => 'a'], [], ['hx-request' => 'true'], '');
        $view = $this->createView($db, $request);
        $controller = new AdminSearchController($view, $db);

        $response = $controller->index($request);

        $this->assertSame(422, $response->getStatus());
    }

    public function testHighlightEscapesHtml(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'admin', 'admin@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'pages.edit', 'Pages edit', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        $pdo->exec("INSERT INTO pages (id, title, slug, status, content, updated_at) VALUES (1, '<script>alert(1)</script>', 'x', 'draft', 'x', '2026-01-01 00:00:00')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $session = $this->buildSession(1);
        $request = new Request('GET', '/admin/search', ['q' => 'alert'], [], ['hx-request' => 'true'], '');
        $request->setSession($session);
        $view = $this->createView($db, $request);
        $controller = new AdminSearchController($view, $db);

        $response = $controller->index($request);
        $body = $response->getBody();

        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
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
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE media_files (id INTEGER PRIMARY KEY AUTOINCREMENT, original_name TEXT, mime_type TEXT, disk_path TEXT, size_bytes INTEGER, created_at TEXT, uploaded_by INTEGER)');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        $settings = new SettingsProvider($db, [
            'site_name' => 'LAAS',
            'default_locale' => 'en',
            'theme' => 'admin',
        ], ['site_name', 'default_locale', 'theme']);

        $themeManager = new ThemeManager($this->rootPath . '/themes', 'admin', $settings);
        $engine = new TemplateEngine(
            $themeManager,
            new TemplateCompiler(),
            $this->rootPath . '/storage/cache/templates',
            true
        );
        $translator = new Translator($this->rootPath, 'admin', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => true],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }

    private function buildSession(int $userId): InMemorySession
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        return $session;
    }
}
