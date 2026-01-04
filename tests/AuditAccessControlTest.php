<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Admin\Controller\AuditController;
use Laas\Settings\SettingsProvider;
use Laas\I18n\Translator;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;

final class AuditAccessControlTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
        }
    }

    public function testAuditDeniedWithoutPermission(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();
        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'admin', 'admin@example.com')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['user_id'] = 1;

        $request = new Request('GET', '/admin/audit', [], [], ['accept' => 'application/json'], '');
        $view = $this->createView($db, $request);
        $controller = new AuditController($view, $db);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatus());
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
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, entity TEXT, entity_id INTEGER, user_id INTEGER, ip TEXT, context TEXT, created_at TEXT)');

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
            new NullAuthService(),
            $settings,
            $this->rootPath . '/storage/cache/templates',
            $db
        );
        $view->setRequest($request);

        return $view;
    }
}
