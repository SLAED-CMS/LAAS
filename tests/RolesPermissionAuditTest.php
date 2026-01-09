<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\RolesController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class RolesPermissionAuditTest extends TestCase
{
    private string $rootPath;
    private int $userId = 1001;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testPermissionUpdatesAreAudited(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username) VALUES ({$this->userId}, 'admin')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (2, 'editor', 'Editor', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'rbac.manage', 'RBAC manage', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (2, 'pages.edit', 'Pages edit', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (3, 'media.view', 'Media view', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (2, 2)");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES ({$this->userId}, 1)");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $this->userId);
        $request = new Request('POST', '/admin/users/roles/save', [], [
            '_token' => 'token',
            'id' => '2',
            'name' => 'editor',
            'title' => 'Editor',
            'permissions' => ['pages.edit', 'media.view'],
        ], [
            'hx-request' => 'true',
        ], '');
        $request->setSession($session);
        $view = $this->createView($db, $request);
        $controller = new RolesController($view, $db);

        $response = $controller->save($request);
        $this->assertSame(200, $response->getStatus());

        $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'rbac.role.permissions.updated'")->fetchColumn();
        $this->assertSame(1, $auditCount);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');
        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER NULL,
            context TEXT NULL,
            ip_address TEXT NULL,
            created_at TEXT NOT NULL
        )');

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
            false
        );
        $translator = new Translator($this->rootPath, 'admin', 'en');
        $view = new View(
            $themeManager,
            $engine,
            $translator,
            'en',
            ['name' => 'LAAS', 'debug' => false],
            new AssetManager([]),
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}
