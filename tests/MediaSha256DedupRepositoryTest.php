<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Repository\MediaRepository;
use PHPUnit\Framework\TestCase;

final class MediaSha256DedupRepositoryTest extends TestCase
{
    public function testListSha256DuplicatesReport(): void
    {
        $repo = $this->createRepository();

        $rows = $repo->listSha256DuplicatesReport(10, null);
        $this->assertCount(1, $rows);
        $this->assertSame('h1', $rows[0]['sha256']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertCount(2, $rows[0]['items']);

        $local = $repo->listSha256DuplicatesReport(10, 'local');
        $this->assertCount(1, $local);

        $s3 = $repo->listSha256DuplicatesReport(10, 's3');
        $this->assertSame([], $s3);
    }

    private function createRepository(): MediaRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL,
            disk_path TEXT NOT NULL,
            disk TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            uploaded_by INTEGER NULL,
            created_at TEXT NOT NULL,
            is_public INTEGER NOT NULL DEFAULT 0,
            public_token TEXT NULL,
            status TEXT NOT NULL,
            quarantine_path TEXT NULL
        )');

        $stmt = $pdo->prepare(
            'INSERT INTO media_files (uuid, disk_path, disk, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token, status, quarantine_path)
             VALUES (:uuid, :disk_path, :disk, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token, :status, :quarantine_path)'
        );

        $now = '2026-01-01 00:00:00';
        $stmt->execute([
            'uuid' => 'u1',
            'disk_path' => 'uploads/2026/01/a.png',
            'disk' => 'local',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => 'h1',
            'uploaded_by' => null,
            'created_at' => $now,
            'is_public' => 0,
            'public_token' => null,
            'status' => 'ready',
            'quarantine_path' => null,
        ]);
        $stmt->execute([
            'uuid' => 'u2',
            'disk_path' => 'uploads/2026/01/b.png',
            'disk' => 'local',
            'original_name' => 'b.png',
            'mime_type' => 'image/png',
            'size_bytes' => 11,
            'sha256' => 'h1',
            'uploaded_by' => null,
            'created_at' => $now,
            'is_public' => 0,
            'public_token' => null,
            'status' => 'ready',
            'quarantine_path' => null,
        ]);
        $stmt->execute([
            'uuid' => 'u3',
            'disk_path' => 'uploads/2026/01/c.png',
            'disk' => 's3',
            'original_name' => 'c.png',
            'mime_type' => 'image/png',
            'size_bytes' => 12,
            'sha256' => 'h1',
            'uploaded_by' => null,
            'created_at' => $now,
            'is_public' => 0,
            'public_token' => null,
            'status' => 'ready',
            'quarantine_path' => null,
        ]);

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return new MediaRepository($db);
    }
}
