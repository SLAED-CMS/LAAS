<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Media\Controller\AdminMediaPickerController;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class MediaPickerTest extends TestCase
{
    private string $rootPath;
    private int $userId = 1001;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
    }

    public function testPickerIndexReturns200(): void
    {
        $db = $this->createDatabase();
        $this->seedRbac($db->pdo(), $this->userId, ['media.view']);
        $db->pdo()->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES (1, 'uuid', 'uploads/2026/01/a.jpg', 'a.jpg', 'image/jpeg', 10, 'hash', {$this->userId}, '2026-01-02 12:00:00')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('GET', '/admin/media/picker', [], [], [], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaPickerController($view, $service, $container);

        $response = $controller->index($request);
        $this->assertSame(200, $response->getStatus());
    }

    public function testPickerSearchFiltersResults(): void
    {
        $db = $this->createDatabase();
        $this->seedRbac($db->pdo(), $this->userId, ['media.view']);
        $db->pdo()->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES (1, 'uuid', 'uploads/2026/01/a.jpg', 'alpha.jpg', 'image/jpeg', 10, 'hash', {$this->userId}, '2026-01-02 12:00:00')");
        $db->pdo()->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES (2, 'uuid2', 'uploads/2026/01/b.pdf', 'beta.pdf', 'application/pdf', 10, 'hash2', {$this->userId}, '2026-01-02 12:00:00')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('GET', '/admin/media/picker', ['q' => 'alpha'], [], [
            'hx-request' => 'true',
        ], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaPickerController($view, $service, $container);

        $response = $controller->index($request);
        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('alpha.jpg', $response->getBody());
        $this->assertStringNotContainsString('beta.pdf', $response->getBody());
    }

    public function testPickerSelectReturnsPayload(): void
    {
        $db = $this->createDatabase();
        $this->seedRbac($db->pdo(), $this->userId, ['media.view']);
        $db->pdo()->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES (1, 'uuid', 'uploads/2026/01/a.jpg', 'alpha.jpg', 'image/jpeg', 10, 'hash', {$this->userId}, '2026-01-02 12:00:00')");

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('POST', '/admin/media/picker/select', [], [
            'media_id' => '1',
        ], [
            'hx-request' => 'true',
        ], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaPickerController($view, $service, $container);

        $response = $controller->select($request);
        $this->assertSame(200, $response->getStatus());
        $this->assertNotNull($response->getHeader('HX-Trigger'));
    }

    public function testPickerRbacEnforced(): void
    {
        $db = $this->createDatabase();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $request = new Request('GET', '/admin/media/picker', [], [], [], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaPickerController($view, $service, $container);

        $response = $controller->index($request);
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
        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL
        )');

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
        RequestScope::set('db.manager', $db);
        if ($hasRequestId) {
            RequestScope::set('request.id', $requestId);
        }

        return $view;
    }

    private function attachSession(Request $request): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $this->userId);
        $request->setSession($session);
    }
}
