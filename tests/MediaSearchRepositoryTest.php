<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Repository\MediaRepository;
use PHPUnit\Framework\TestCase;

final class MediaSearchRepositoryTest extends TestCase
{
    public function testSearchFiltersByNameAndMime(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
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

        $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'admin')");
        $pdo->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES ('u1', 'uploads/2026/01/a.jpg', 'alpha.jpg', 'image/jpeg', 10, 'h1', 1, '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at)
            VALUES ('u2', 'uploads/2026/01/b.pdf', 'beta.pdf', 'application/pdf', 10, 'h2', 1, '2026-01-01 00:00:00')");

        $repo = new MediaRepository($db);
        $byName = $repo->search('alpha', 10, 0);
        $this->assertCount(1, $byName);
        $this->assertSame('alpha.jpg', $byName[0]['original_name']);

        $byMime = $repo->search('application/pdf', 10, 0);
        $this->assertCount(1, $byMime);
        $this->assertSame('beta.pdf', $byMime[0]['original_name']);
    }
}
