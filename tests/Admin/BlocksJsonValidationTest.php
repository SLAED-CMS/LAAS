<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Pages\Controller\AdminPagesController;
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

final class BlocksJsonValidationTest extends TestCase
{
    private string $rootPath;
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
        RequestScope::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
    }

    public function testInvalidBlocksJsonDoesNotPersist(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();

        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'admin', 'admin@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permissions (id, name, title, created_at, updated_at) VALUES (1, 'pages.edit', 'Pages edit', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO pages (id, title, slug, content, status, created_at, updated_at) VALUES (1, 'Old', 'old', '<p>Old</p>', 'draft', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $session = $this->buildSession(1);
        $request = new Request('POST', '/admin/pages/save', [], [
            'id' => '1',
            'title' => 'New',
            'slug' => 'old',
            'content' => '<p>New</p>',
            'status' => 'draft',
            'blocks_json' => '[{"type":"missing","data":{}}]',
        ], [], '');
        $request->setSession($session);

        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new AdminPagesController($view, $service, $service, $container);

        $response = $controller->save($request);

        $this->assertSame(422, $response->getStatus());
        $this->assertStringContainsString('Blocks JSON is invalid.', $response->getBody());
        $this->assertSame('Old', (string) $pdo->query('SELECT title FROM pages WHERE id = 1')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pages_revisions')->fetchColumn());
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
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, content TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE pages_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, blocks_json TEXT, created_at TEXT, created_by INTEGER)');

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
