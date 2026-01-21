<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Media\MediaService;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\AdminMediaController;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;
use Tests\Support\InMemorySession;

final class AdminMediaUploadServiceTest extends TestCase
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

    public function testUploadUsesMediaService(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        SecurityTestHelper::insertUser($pdo, 1, 'admin', 'hash');
        SecurityTestHelper::insertRole($pdo, 1, 'admin');
        SecurityTestHelper::insertPermission($pdo, 1, 'media.upload');
        SecurityTestHelper::assignRole($pdo, 1, 1);
        SecurityTestHelper::grantPermission($pdo, 1, 1);

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $request = new Request('POST', '/admin/media/upload', [], [], ['accept' => 'application/json'], '');
        $session = new InMemorySession();
        $session->start();
        $session->set('user_id', 1);
        $request->setSession($session);

        $view = SecurityTestHelper::createView($db, $request, 'admin');
        $service = new SpyMediaService($db);
        $container = SecurityTestHelper::createContainer($db);
        $controller = new AdminMediaController($view, $service, $service, $container);

        $tmp = $this->createTempPng();
        $size = (int) (filesize($tmp) ?: 0);
        $_SERVER['CONTENT_LENGTH'] = (string) $size;
        $_FILES['file'] = [
            'name' => 'pixel.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => $size,
        ];

        $response = $controller->upload($request);

        $this->assertSame(201, $response->getStatus());
        $this->assertTrue($service->called);
        $this->assertSame('pixel.png', $service->lastFile['name'] ?? null);
        $this->assertSame($tmp, $service->lastFile['tmp_path'] ?? null);
        $this->assertSame('image/png', $service->lastFile['mime'] ?? null);
        $this->assertTrue(is_file($tmp));
        @unlink($tmp);
    }

    private function createTempPng(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laas_media_');
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
}

final class SpyMediaService extends MediaService
{
    public bool $called = false;
    public array $lastFile = [];
    public array $lastOptions = [];

    public function __construct(DatabaseManager $db)
    {
        parent::__construct($db, [], sys_get_temp_dir());
    }

    public function upload(array $file, array $options = []): array
    {
        $this->called = true;
        $this->lastFile = $file;
        $this->lastOptions = $options;

        return [
            'id' => 123,
            'mime_type' => 'image/png',
            'size_bytes' => 1,
            'sha256' => 'hash',
            'existing' => false,
        ];
    }
}
