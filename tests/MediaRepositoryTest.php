<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Repository\MediaRepository;
use PHPUnit\Framework\TestCase;

final class MediaRepositoryTest extends TestCase
{
    private function createRepository(): MediaRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL
        )');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return new MediaRepository($db);
    }

    public function testInsertAndFindById(): void
    {
        $repo = $this->createRepository();
        $id = $repo->create([
            'uuid' => 'uuid-test',
            'disk_path' => 'uploads/2026/01/uuid-test.jpg',
            'original_name' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123,
            'sha256' => 'hash-test',
            'uploaded_by' => 1,
        ]);

        $row = $repo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('uuid-test', $row['uuid']);
    }

    public function testFindBySha256(): void
    {
        $repo = $this->createRepository();
        $id = $repo->create([
            'uuid' => 'uuid-test-2',
            'disk_path' => 'uploads/2026/01/uuid-test-2.jpg',
            'original_name' => 'test2.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 321,
            'sha256' => 'hash-dup',
            'uploaded_by' => 1,
        ]);

        $row = $repo->findBySha256('hash-dup');
        $this->assertNotNull($row);
        $this->assertSame($id, (int) $row['id']);
    }
}
