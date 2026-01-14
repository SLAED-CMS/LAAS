<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\AdminMediaController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminMediaUploadJsonTest extends TestCase
{
    private array $serverBackup = [];
    private array $filesBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = [
            'CONTENT_LENGTH' => [
                'present' => array_key_exists('CONTENT_LENGTH', $_SERVER),
                'value' => $_SERVER['CONTENT_LENGTH'] ?? null,
            ],
            'REQUEST_TIME_FLOAT' => [
                'present' => array_key_exists('REQUEST_TIME_FLOAT', $_SERVER),
                'value' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
            ],
        ];
        $this->filesBackup = $_FILES;

        $_SERVER['CONTENT_LENGTH'] = '';
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $state) {
            if (empty($state['present'])) {
                unset($_SERVER[$key]);
                continue;
            }
            $_SERVER[$key] = $state['value'];
        }
        $_FILES = $this->filesBackup;
    }

    public function testUploadReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedMediaUpload($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/media/upload');
        $controller = $this->createController($pdo, $request);

        $tmp = $this->createTempPng();
        $this->setUploadFile('photo.png', $tmp);

        try {
            $response = $controller->upload($request);
        } finally {
            $this->clearUploadFiles();
        }

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.media.upload', $payload['meta']['route'] ?? null);
        $this->assertTrue($payload['data']['id'] > 0);
        $this->assertSame('image/png', $payload['data']['mime'] ?? null);
    }

    public function testUploadInvalidMimeReturnsError(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedMediaUpload($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/media/upload');
        $controller = $this->createController($pdo, $request);

        $tmp = $this->createTempText();
        $this->setUploadFile('note.txt', $tmp);

        try {
            $response = $controller->upload($request);
        } finally {
            $this->clearUploadFiles();
        }

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('application/json; charset=utf-8', $response->getHeader('Content-Type'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame(ErrorCode::VALIDATION_FAILED, $payload['error']['code'] ?? null);
        $this->assertSame('json', $payload['meta']['format'] ?? null);
        $this->assertSame('admin.media.upload', $payload['meta']['route'] ?? null);
    }

    private function createBaseSchema(): \PDO
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        return $pdo;
    }

    private function seedMediaUpload(\PDO $pdo, int $userId): void
    {
        SecurityTestHelper::insertUser($pdo, $userId, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'media.upload');
        SecurityTestHelper::assignRole($pdo, $userId, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);
        return $request;
    }

    private function createController(\PDO $pdo, Request $request): AdminMediaController
    {
        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $view = $this->createView($db, $request);
        return new AdminMediaController($view, $db);
    }

    private function createView(DatabaseManager $db, Request $request): View
    {
        return SecurityTestHelper::createView($db, $request, 'admin');
    }

    private function createTempPng(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_img_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }

        $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8r/0sAAAAASUVORK5CYII=', true);
        if ($data === false) {
            $this->fail('Failed to decode PNG data');
        }
        file_put_contents($tmp, $data);
        return $tmp;
    }

    private function createTempText(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_txt_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }

        file_put_contents($tmp, "not an image");
        return $tmp;
    }

    private function setUploadFile(string $name, string $tmpPath): void
    {
        $_FILES['file'] = [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $tmpPath,
            'error' => 0,
            'size' => filesize($tmpPath) ?: 0,
        ];
    }

    private function clearUploadFiles(): void
    {
        unset($_FILES['file']);
    }
}
