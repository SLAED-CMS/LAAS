<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\DevTools\Controller\DevToolsController;
use Laas\Settings\SettingsProvider;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class DevToolsProdDisableTest extends TestCase
{
    private string $rootPath;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
        $this->envBackup = [
            'APP_ENV' => $_ENV['APP_ENV'] ?? null,
            'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? null,
            'DEVTOOLS_ENABLED' => $_ENV['DEVTOOLS_ENABLED'] ?? null,
        ];
        $_ENV['APP_ENV'] = 'prod';
        $_ENV['APP_DEBUG'] = 'true';
        $_ENV['DEVTOOLS_ENABLED'] = 'true';
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
                continue;
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    public function testDevToolsDisabledInProd(): void
    {
        $db = $this->createDatabase();
        $this->seedRbac($db->pdo(), 1, ['debug.view']);

        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request = new Request('GET', '/__devtools/ping', [], [], [], '');
        $request->setSession($session);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $controller = new DevToolsController($view, null, $container);

        $response = $controller->ping($request);
        $this->assertSame(403, $response->getStatus());
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec('CREATE TABLE permission_role (role_id INTEGER, permission_id INTEGER)');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function seedRbac(PDO $pdo, int $userId, array $permissions): void
    {
        $pdo->exec("INSERT INTO users (id, username) VALUES ({$userId}, 'admin')");
        $pdo->exec("INSERT INTO roles (id, name, title) VALUES (1, 'admin', 'Admin')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES ({$userId}, 1)");

        $permId = 1;
        foreach ($permissions as $perm) {
            $pdo->exec("INSERT INTO permissions (id, name, title) VALUES ({$permId}, '{$perm}', '{$perm}')");
            $pdo->exec("INSERT INTO permission_role (role_id, permission_id) VALUES (1, {$permId})");
            $permId++;
        }
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
