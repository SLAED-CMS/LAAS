<?php
declare(strict_types=1);

use Laas\Auth\NullAuthService;
use Laas\Database\DatabaseManager;
use Laas\Domain\Media\MediaService;
use Laas\Http\Request;
use Laas\I18n\Translator;
use Laas\Modules\Media\Controller\AdminMediaController;
use Laas\Security\CacheRateLimiterStore;
use Laas\Security\RateLimiter;
use Laas\Settings\SettingsProvider;
use Laas\Support\Cache\CacheFactory;
use Laas\Support\RequestScope;
use Laas\View\Template\TemplateCompiler;
use Laas\View\Template\TemplateEngine;
use Laas\View\Theme\ThemeManager;
use Laas\View\AssetManager;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class MediaRateLimitTest extends TestCase
{
    private string $rootPath;
    private int $userId;
    private string $ipUpload;
    private string $ipDelete;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
        $this->userId = 9001;
        $this->ipUpload = '127.0.0.101';
        $this->ipDelete = '127.0.0.102';
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        RequestScope::reset();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimit('media_upload_ip', $this->ipUpload);
        $this->clearRateLimit('media_upload_ip', $this->ipDelete);
        $this->clearRateLimit('media_upload_user', 'user:' . $this->userId);
    }

    public function testMediaUploadRateLimitExceeded(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();
        $this->seedRbac($pdo, $this->userId, ['media.upload']);

        $_SERVER['REMOTE_ADDR'] = $this->ipUpload;

        $security = require $this->rootPath . '/config/security.php';
        $rate = $security['rate_limit']['media_upload'];
        $window = (int) $rate['window'];
        $max = (int) $rate['max'];

        $this->clearRateLimit('media_upload_ip', $this->ipUpload);
        $this->clearRateLimit('media_upload_user', 'user:' . $this->userId);

        $limiter = new RateLimiter($this->rootPath);
        for ($i = 0; $i < $max; $i++) {
            $limiter->hit('media_upload_ip', $this->ipUpload, $window, $max);
            $limiter->hit('media_upload_user', 'user:' . $this->userId, $window, $max);
        }

        $request = new Request('POST', '/admin/media/upload', [], [], [
            'hx-request' => 'true',
        ], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = $this->createService($db);
        $controller = new AdminMediaController($view, $service, $service, $container);

        $response = $controller->upload($request);
        $this->assertSame(429, $response->getStatus());
        $this->assertStringContainsString('Too many uploads', $response->getBody());
    }

    public function testRateLimitBypassOtherEndpoints(): void
    {
        $db = $this->createDatabase();
        $pdo = $db->pdo();
        $this->seedRbac($pdo, $this->userId, ['media.delete']);
        $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES (1, 'uuid-test', 'uploads/2026/01/uuid-test.jpg', 'test.jpg', 'image/jpeg', 10, 'hash', {$this->userId}, '2026-01-02 12:00:00')");

        $_SERVER['REMOTE_ADDR'] = $this->ipDelete;

        $security = require $this->rootPath . '/config/security.php';
        $rate = $security['rate_limit']['media_upload'];
        $window = (int) $rate['window'];
        $max = (int) $rate['max'];

        $this->clearRateLimit('media_upload_ip', $this->ipDelete);
        $this->clearRateLimit('media_upload_user', 'user:' . $this->userId);
        $limiter = new RateLimiter($this->rootPath);
        for ($i = 0; $i < $max; $i++) {
            $limiter->hit('media_upload_ip', $this->ipDelete, $window, $max);
            $limiter->hit('media_upload_user', 'user:' . $this->userId, $window, $max);
        }

        $request = new Request('POST', '/admin/media/delete', [], [
            'id' => '1',
        ], [
            'hx-request' => 'true',
        ], '');
        $this->attachSession($request);
        $view = $this->createView($db, $request);
        $container = SecurityTestHelper::createContainer($db);
        $service = $this->createService($db);
        $controller = new AdminMediaController($view, $service, $service, $container);

        $response = $controller->delete($request);
        $this->assertSame(200, $response->getStatus());
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

    private function createService(DatabaseManager $db): MediaService
    {
        $config = require $this->rootPath . '/config/media.php';
        if (!is_array($config)) {
            $config = [];
        }

        return new MediaService($db, $config, $this->rootPath);
    }

    private function clearRateLimit(string $group, string $key): void
    {
        $store = new CacheRateLimiterStore(CacheFactory::create($this->rootPath));
        $store->delete($group . ':' . $key);
    }

    private function attachSession(Request $request): void
    {
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $this->userId);
        $request->setSession($session);
    }
}
