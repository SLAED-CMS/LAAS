<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Admin\Controller\RbacDiagnosticsController;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\Support\Rbac\RbacDiagnosticsService;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class RbacDiagnosticsTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testDiagnosticsRequiresPermission(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email, status, created_at) VALUES (1, 'admin', 'admin@example.com', 1, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('GET', '/admin/rbac/diagnostics', [], [], [], '');
        $this->attachSession($request, 1);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $usersService = new \Laas\Domain\Users\UsersService($db);
        $controller = new RbacDiagnosticsController($view, $usersService, $container);

        $response = $controller->index($request);
        $this->assertSame(403, $response->getStatus());
    }

    public function testExplainPermissionReturnsRoles(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email, status, created_at) VALUES (1, 'admin', 'admin@example.com', 1, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'rbac.diagnostics', 'RBAC diagnostics', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");

        $rbacService = new \Laas\Domain\Rbac\RbacService($db);
        $usersService = new \Laas\Domain\Users\UsersService($db);
        $service = new RbacDiagnosticsService($rbacService, $usersService);
        $result = $service->explainPermission(1, 'rbac.diagnostics');

        $this->assertTrue($result['allowed']);
        $this->assertSame(['admin'], $result['roles']);
    }

    public function testAuditEntryCreated(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email, status, created_at) VALUES (1, 'admin', 'admin@example.com', 1, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'rbac.diagnostics', 'RBAC diagnostics', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('GET', '/admin/rbac/diagnostics', ['user_id' => '1'], [], ['hx-request' => 'true'], '');
        $this->attachSession($request, 1);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $usersService = new \Laas\Domain\Users\UsersService($db);
        $controller = new RbacDiagnosticsController($view, $usersService, $container);

        $response = $controller->index($request);
        $this->assertSame(200, $response->getStatus());

        $count = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'rbac.diagnostics.viewed'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, status INTEGER, last_login_at TEXT, last_login_ip TEXT, created_at TEXT)');
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
        $hasRequestId = RequestScope::has('request.id');
        $requestId = RequestScope::get('request.id');
        RequestScope::reset();
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
        RequestScope::set('db.manager', $db);
        if ($hasRequestId) {
            RequestScope::set('request.id', $requestId);
        }

        return $view;
    }

    private function attachSession(Request $request, int $userId): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
    }
}
