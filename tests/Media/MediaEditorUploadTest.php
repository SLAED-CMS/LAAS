<?php
declare(strict_types=1);

use Laas\Domain\Media\MediaService;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\AdminMediaController;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class MediaEditorUploadTest extends TestCase
{
    private string $rootPath;
    private int $userId = 1001;
    private ?string $previousAppEnv = null;

    protected function setUp(): void
    {
        $this->rootPath = SecurityTestHelper::rootPath();
        $this->previousAppEnv = getenv('APP_ENV') ?: null;
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        if ($this->previousAppEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousAppEnv);
        }
        $_FILES = [];
    }

    public function testUploadEditorForbiddenWithoutPermission(): void
    {
        $db = $this->createDatabase();
        $request = $this->makeRequest('POST', '/admin/media/upload-editor', $this->userId);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = SecurityTestHelper::createContainer($db);
        $service = new MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaController($view, $service, $service, $container);

        $response = $controller->uploadEditor($request);

        $this->assertSame(403, $response->getStatus());
        $this->assertStringContainsString('"error"', $response->getBody());
    }

    public function testUploadEditorStoresImageAndReturnsLocation(): void
    {
        $db = $this->createDatabase();
        $this->seedRbac($db->pdo(), $this->userId, ['media.upload']);

        $tmp = $this->createTempPng();
        $size = (int) filesize($tmp);

        $_FILES['file'] = [
            'name' => 'tiny.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'size' => $size,
            'error' => 0,
        ];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $request = $this->makeRequest('POST', '/admin/media/upload-editor', $this->userId, [
            'accept' => 'application/json',
            'content-length' => (string) $size,
        ]);
        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $container = SecurityTestHelper::createContainer($db);
        $service = new MediaService($db, [], $this->rootPath);
        $controller = new AdminMediaController($view, $service, $service, $container);

        $response = $controller->uploadEditor($request);

        $this->assertSame(200, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('location', $payload);
        $this->assertArrayHasKey('id', $payload);
        $this->assertStringContainsString('/media/', (string) $payload['location']);
        $this->assertGreaterThan(0, (int) $payload['id']);

        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    private function createDatabase(): \Laas\Database\DatabaseManager
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        SecurityTestHelper::seedSettingsTable($pdo);
        SecurityTestHelper::seedAuditTable($pdo);
        return SecurityTestHelper::dbManagerFromPdo($pdo);
    }

    private function seedRbac(PDO $pdo, int $userId, array $permissions): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::assignRole($pdo, $userId, 1);

        $permId = 1;
        foreach ($permissions as $permission) {
            SecurityTestHelper::insertPermission($pdo, $permId, $permission);
            SecurityTestHelper::grantPermission($pdo, 1, $permId);
            $permId++;
        }
    }

    private function makeRequest(string $method, string $path, int $userId, array $headers = []): Request
    {
        $request = new Request($method, $path, [], [], $headers, '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', $userId);
        $request->setSession($session);
        return $request;
    }

    private function createTempPng(): string
    {
        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
        $tmp = tempnam(sys_get_temp_dir(), 'laas_img_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }
        file_put_contents($tmp, $data === false ? '' : $data);
        return $tmp;
    }
}
