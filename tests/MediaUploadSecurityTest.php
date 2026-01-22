<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service {
    if (!function_exists(__NAMESPACE__ . '\\is_uploaded_file')) {
        function is_uploaded_file(string $filename): bool
        {
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            return rename($from, $to);
        }
    }
}

namespace {
    use Laas\Auth\NullAuthService;
    use Laas\Database\DatabaseManager;
    use Laas\Domain\Media\MediaService;
    use Laas\Http\Request;
    use Laas\I18n\Translator;
    use Laas\Modules\Media\Controller\AdminMediaController;
    use Laas\Modules\Media\Repository\MediaRepository;
    use Laas\Modules\Media\Service\StorageService;
    use Laas\Security\CacheRateLimiterStore;
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

    final class MediaUploadSecurityTest extends TestCase
    {
        private string $rootPath;
        private int $userId;

        protected function setUp(): void
        {
            $this->rootPath = dirname(__DIR__);
            $this->userId = 9002;
            $this->clearRateLimit();
            $_FILES = [];
            $_SERVER['CONTENT_LENGTH'] = '';
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        }

        public function testRejectUploadByContentLength(): void
        {
            $db = $this->createDatabase();
            $this->seedRbac($db->pdo(), $this->userId, ['media.upload']);

            $security = require $this->rootPath . '/config/media.php';
            $max = (int) ($security['max_bytes'] ?? 0);

            $_SERVER['REMOTE_ADDR'] = '127.0.0.10';
            $_SERVER['CONTENT_LENGTH'] = (string) ($max + 1);

            $request = new Request('POST', '/admin/media/upload', [], [], [
                'content-length' => (string) ($max + 1),
                'hx-request' => 'true',
            ], '');
            $this->attachSession($request);
            $controller = $this->createController($db, $request);

            $response = $controller->upload($request);
            $this->assertSame(413, $response->getStatus());
        }

        public function testRejectUploadByFilesSize(): void
        {
            $db = $this->createDatabase();
            $this->seedRbac($db->pdo(), $this->userId, ['media.upload']);

            $media = require $this->rootPath . '/config/media.php';
            $max = (int) ($media['max_bytes'] ?? 0);

            $_SERVER['REMOTE_ADDR'] = '127.0.0.11';
            $_SERVER['CONTENT_LENGTH'] = (string) ($max);

            $_FILES['file'] = [
                'name' => 'big.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => 'x',
                'error' => 0,
                'size' => $max + 1,
            ];

            $request = new Request('POST', '/admin/media/upload', [], [], [
                'content-length' => (string) $max,
                'hx-request' => 'true',
            ], '');
            $this->attachSession($request);
            $controller = $this->createController($db, $request);

            $response = $controller->upload($request);
            $this->assertSame(413, $response->getStatus());
        }

        public function testRejectSvgUpload(): void
        {
            $db = $this->createDatabase();
            $this->seedRbac($db->pdo(), $this->userId, ['media.upload']);

            $svg = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"></svg>";
            $tmp = tempnam(sys_get_temp_dir(), 'laas_upload_');
            file_put_contents($tmp, $svg);
            $size = filesize($tmp) ?: 0;

            $_SERVER['REMOTE_ADDR'] = '127.0.0.13';
            $_SERVER['CONTENT_LENGTH'] = (string) $size;

            $_FILES['file'] = [
                'name' => 'icon.svg',
                'type' => 'image/svg+xml',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];

            $request = new Request('POST', '/admin/media/upload', [], [], [
                'content-length' => (string) $size,
                'hx-request' => 'true',
            ], '');
            $this->attachSession($request);
            $controller = $this->createController($db, $request);

            $response = $controller->upload($request);
            $this->assertSame(422, $response->getStatus());
        }

        public function testAcceptValidUpload(): void
        {
            $db = $this->createDatabase();
            $this->seedRbac($db->pdo(), $this->userId, ['media.upload']);

            $media = require $this->rootPath . '/config/media.php';
            $max = (int) ($media['max_bytes'] ?? 0);

            $tmp = tempnam(sys_get_temp_dir(), 'laas_upload_');
            $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true) ?: '';
            file_put_contents($tmp, $data);
            $size = filesize($tmp) ?: 0;

            $_SERVER['REMOTE_ADDR'] = '127.0.0.12';
            $_SERVER['CONTENT_LENGTH'] = (string) $size;

            $_FILES['file'] = [
                'name' => 'pixel.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => 0,
                'size' => $size,
            ];

            $request = new Request('POST', '/admin/media/upload', [], [], [
                'content-length' => (string) $size,
                'hx-request' => 'true',
            ], '');
            $this->attachSession($request);
            $controller = $this->createController($db, $request);

            $response = $controller->upload($request);
            $this->assertSame(200, $response->getStatus());

            $repo = new MediaRepository($db);
            $rows = $repo->list(10, 0, '');
            $this->assertNotEmpty($rows);

            $storage = new StorageService($this->rootPath);
            $storage->delete((string) ($rows[0]['disk_path'] ?? ''));
        }

        private function createDatabase(): DatabaseManager
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

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
            $ref = new \ReflectionProperty($db, 'pdo');
            $ref->setAccessible(true);
            $ref->setValue($db, $pdo);

            return $db;
        }

        private function seedRbac(\PDO $pdo, int $userId, array $permissions): void
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

        private function createController(DatabaseManager $db, Request $request): AdminMediaController
        {
            $view = $this->createView($db, $request);
            $container = SecurityTestHelper::createContainer($db);
            $config = require $this->rootPath . '/config/media.php';
            if (!is_array($config)) {
                $config = [];
            }
            $service = new MediaService($db, $config, $this->rootPath);

            return new AdminMediaController($view, $service, $service, $container);
        }

        private function clearRateLimit(): void
        {
            $store = new CacheRateLimiterStore(CacheFactory::create($this->rootPath));
            $store->delete('media_upload_user:user:' . $this->userId);

            $ips = ['127.0.0.10', '127.0.0.11', '127.0.0.12', '127.0.0.13'];
            foreach ($ips as $ip) {
                $store->delete('media_upload_ip:' . $ip);
            }
        }

        private function attachSession(Request $request): void
        {
            $session = new InMemorySession();
            $session->start();
            $session->set('user_id', $this->userId);
            $request->setSession($session);
        }
    }
}
