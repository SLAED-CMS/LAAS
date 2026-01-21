<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Media\Controller\MediaServeController;
use Laas\Modules\Media\Service\StorageService;
use PHPUnit\Framework\TestCase;

final class MediaServeHeaderSafetyTest extends TestCase
{
    private string $rootPath;
    private ?string $prevPublicMode = null;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__);
        $this->prevPublicMode = $_ENV['MEDIA_PUBLIC_MODE'] ?? null;
        $_ENV['MEDIA_PUBLIC_MODE'] = 'all';
    }

    protected function tearDown(): void
    {
        if ($this->prevPublicMode === null) {
            unset($_ENV['MEDIA_PUBLIC_MODE']);
        } else {
            $_ENV['MEDIA_PUBLIC_MODE'] = $this->prevPublicMode;
        }
    }

    public function testContentDispositionSanitizesFilename(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
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
            is_public INTEGER NOT NULL DEFAULT 1,
            public_token TEXT NULL
        )');

        $diskPath = 'uploads/2026/01/test.jpg';
        $storage = new StorageService($this->rootPath);
        $storage->putContents($diskPath, 'data');

        $originalName = '../../evil\\name.jpg';
        $stmt = $pdo->prepare('INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, created_at, is_public)
            VALUES (:id, :uuid, :disk_path, :original_name, :mime_type, :size_bytes, :created_at, :is_public)');
        $stmt->execute([
            'id' => 1,
            'uuid' => 'u1',
            'disk_path' => $diskPath,
            'original_name' => $originalName,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 4,
            'created_at' => '2026-01-01 00:00:00',
            'is_public' => 1,
        ]);

        $request = new Request('GET', '/media/1/../evil', ['p' => 'view'], [], [], '');
        $service = new \Laas\Domain\Media\MediaService($db, [], $this->rootPath);
        $controller = new MediaServeController(null, $service, null, null, null, $storage);
        $response = $controller->serve($request, ['id' => 1, 'name' => '../evil']);

        $header = (string) $response->getHeader('Content-Disposition');
        $this->assertStringContainsString('filename="', $header);
        $this->assertStringNotContainsString('..', $header);
        $this->assertStringNotContainsString('/', $header);
        $this->assertStringNotContainsString('\\', $header);

        $storage->delete($diskPath);
    }
}
