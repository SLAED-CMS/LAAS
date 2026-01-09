<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Changelog\Controller\AdminChangelogController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class AdminControllerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function testRequiresPermission(): void
    {
        $db = $this->createDatabase();
        $request = new Request('GET', '/admin/changelog', [], [], [], '');
        $request->setSession($this->buildSession(1));
        $view = $this->createView($db, $request);

        $controller = new AdminChangelogController($view, $db);
        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
    }

    public function testSaveValidates(): void
    {
        $db = $this->createDatabase();
        $this->seedUserWithPermission($db, 1, 'changelog.admin');

        $post = ['source_type' => 'evil'];
        $request = new Request('POST', '/admin/changelog/save', [], $post, ['hx-request' => 'true'], '');
        $request->setSession($this->buildSession(1));
        $view = $this->createView($db, $request);

        $controller = new AdminChangelogController($view, $db);
        $response = $controller->save($request);

        $this->assertSame(422, $response->getStatus());
    }

    public function testCacheClearLogsAudit(): void
    {
        $db = $this->createDatabase();
        $this->seedUserWithPermission($db, 1, 'changelog.cache.clear');

        $request = new Request('POST', '/admin/changelog/cache/clear', [], [], ['hx-request' => 'true'], '');
        $request->setSession($this->buildSession(1));
        $view = $this->createView($db, $request);

        $controller = new AdminChangelogController($view, $db);
        $response = $controller->clearCache($request);

        $this->assertSame(200, $response->getStatus());
        $stmt = $db->pdo()->query('SELECT COUNT(*) AS cnt FROM audit_logs WHERE action = "changelog.cache.cleared"');
        $row = $stmt ? $stmt->fetch() : null;
        $this->assertSame(1, (int) ($row['cnt'] ?? 0));
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, `type` TEXT, `updated_at` TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NULL, action TEXT, entity TEXT, entity_id INT NULL, context TEXT, ip_address TEXT, created_at TEXT)');

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

    private function seedUserWithPermission(DatabaseManager $db, int $userId, string $perm): void
    {
        $pdo = $db->pdo();
        $pdo->exec("INSERT INTO users (id, username, email) VALUES ({$userId}, 'admin', 'admin@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, '{$perm}', '{$perm}', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES ({$userId}, 1)");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");
    }

    private function buildSession(int $userId): InMemorySession
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        return $session;
    }
}
