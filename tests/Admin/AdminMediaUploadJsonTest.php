<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\AdminMediaController;
use Laas\View\View;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminMediaUploadJsonTest extends TestCase
{
    public function testUploadReturnsJsonContract(): void
    {
        $pdo = $this->createBaseSchema();
        $this->seedMediaUpload($pdo, 1);

        $request = $this->makeRequest('POST', '/admin/media/upload');
        $controller = $this->createController($pdo, $request);

        $tmp = $this->createTempJpeg();
        $this->setUploadFile('photo.jpg', $tmp);

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
        $this->assertSame('image/jpeg', $payload['data']['mime'] ?? null);
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
        $this->assertSame('invalid_mime', $payload['error'] ?? null);
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

    private function createTempJpeg(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_img_');
        if ($tmp === false) {
            $this->fail('Failed to create temp file');
        }

        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        file_put_contents($tmp, $jpeg);
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
