<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupVerifyOkTest extends TestCase
{
    public function testVerifyOk(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v3.6.0',
            'env' => 'dev',
        ], [
            'default' => 'local',
        ]);

        $result = $manager->create(['db_driver' => 'pdo']);
        $this->assertTrue($result['ok']);

        $verify = $manager->verify((string) $result['file']);
        $this->assertTrue($verify['ok']);
    }

    private function createEnv(): array
    {
        $root = sys_get_temp_dir() . '/laas_backup_verify_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/uploads/2026/01', 0775, true);
        @mkdir($root . '/storage/backups', 0775, true);
        @mkdir($root . '/storage/tmp', 0775, true);

        $db = $this->createDatabase();
        $storage = new StorageService($root);

        file_put_contents($root . '/storage/uploads/2026/01/file.jpg', 'file');
        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u1','uploads/2026/01/file.jpg','file.jpg','image/jpeg',4,'h1',NULL,'2026-01-01 00:00:00')");

        return [$root, $db, $storage];
    }

    private function createDatabase(): DatabaseManager
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

        $db = new DatabaseManager(['driver' => 'sqlite']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
