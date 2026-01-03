<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    public function testBackupCreateWritesArchive(): void
    {
        $root = sys_get_temp_dir() . '/laas_backup_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/uploads/2026/01', 0775, true);
        @mkdir($root . '/storage/backups', 0775, true);

        $db = $this->createDatabase();
        $storage = new StorageService($root);

        $mediaPath = $root . '/storage/uploads/2026/01/file.jpg';
        file_put_contents($mediaPath, 'file');
        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u1','uploads/2026/01/file.jpg','file.jpg','image/jpeg',4,'h1',NULL,'2026-01-01 00:00:00')");

        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v1.11.0',
        ], [
            'default' => 'local',
        ]);

        $result = $manager->create();
        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['file']);
        $this->assertFileExists($result['file']);

        $zip = new ZipArchive();
        $zip->open($result['file']);
        $this->assertNotFalse($zip->getFromName('metadata.json'));
        $this->assertNotFalse($zip->getFromName('db.sql'));
        $this->assertNotFalse($zip->getFromName('media/uploads/2026/01/file.jpg'));
        $zip->close();
    }

    public function testRestoreRefusesIncompatibleVersion(): void
    {
        $root = sys_get_temp_dir() . '/laas_backup_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/backups', 0775, true);

        $db = $this->createDatabase();
        $storage = new StorageService($root);
        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v1.11.0',
        ], [
            'default' => 'local',
        ]);

        $file = $root . '/storage/backups/backup_test.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('metadata.json', json_encode([
            'version' => 'v0.9.0',
            'timestamp' => '20260101_000000',
            'disk' => 'local',
        ]));
        $zip->addFromString('db.sql', 'SELECT 1;');
        $zip->close();

        $result = $manager->restore($file, true);
        $this->assertFalse($result['ok']);
        $this->assertSame('incompatible_version', $result['error']);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

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
        $ref = new \ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
