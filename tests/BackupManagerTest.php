<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    public function testInspectValidatesChecksums(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);
        $this->assertTrue($result['ok']);

        $verify = $manager->verify($result['file']);
        $this->assertTrue($verify['ok']);
    }

    public function testRestoreRequiresDoubleConfirm(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $restore = $manager->restore($result['file'], [
            'confirm1' => 'NOPE',
            'confirm2' => 'x.zip',
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertSame('confirm_failed', $restore['error']);
    }

    public function testRestoreLockPreventsParallelRun(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $lockPath = $root . '/storage/backups/.restore.lock';
        @mkdir($root . '/storage/backups', 0775, true);
        $handle = fopen($lockPath, 'c+');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        $restore = $manager->restore($result['file'], [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertSame('locked', $restore['error']);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testRestoreForbiddenInProdWithoutForce(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'prod');
        $result = $manager->create(['db_driver' => 'pdo']);

        $restore = $manager->restore($result['file'], [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertSame('forbidden_in_prod', $restore['error']);
    }

    public function testDryRunDoesNotModifyState(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = $this->manager($root, $db, $storage, 'dev');
        $result = $manager->create(['db_driver' => 'pdo']);

        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u2','uploads/2026/01/after.jpg','after.jpg','image/jpeg',4,'h2',NULL,'2026-01-01 00:00:00')");
        file_put_contents($root . '/storage/uploads/2026/01/after.jpg', 'after');

        $restore = $manager->restore($result['file'], [
            'dry_run' => true,
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertTrue($restore['ok']);
        $count = (int) $db->pdo()->query('SELECT COUNT(*) AS c FROM media_files')->fetchColumn();
        $this->assertSame(2, $count);
        $this->assertSame('after', file_get_contents($root . '/storage/uploads/2026/01/after.jpg'));
    }

    public function testRollbackExecutedOnFailure(): void
    {
        [$root, $db, $storage] = $this->createEnv();
        $manager = new TestBackupManager($root, $db, $storage, [
            'version' => 'v1.11.2',
            'env' => 'dev',
        ], [
            'default' => 'local',
        ]);

        $result = $manager->create(['db_driver' => 'pdo']);
        $restore = $manager->restore($result['file'], [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($result['file']),
        ]);

        $this->assertFalse($restore['ok']);
        $this->assertTrue($manager->rollbackCalled);
    }

    private function createEnv(): array
    {
        $root = sys_get_temp_dir() . '/laas_backup_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/uploads/2026/01', 0775, true);
        @mkdir($root . '/storage/backups', 0775, true);

        $db = $this->createDatabase();
        $storage = new StorageService($root);

        $mediaPath = $root . '/storage/uploads/2026/01/file.jpg';
        file_put_contents($mediaPath, 'file');
        $db->pdo()->exec("INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at) VALUES ('u1','uploads/2026/01/file.jpg','file.jpg','image/jpeg',4,'h1',NULL,'2026-01-01 00:00:00')");

        return [$root, $db, $storage];
    }

    private function manager(string $root, DatabaseManager $db, StorageService $storage, string $env): BackupManager
    {
        return new BackupManager($root, $db, $storage, [
            'version' => 'v1.11.2',
            'env' => $env,
        ], [
            'default' => 'local',
        ]);
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

        $db = new DatabaseManager(['driver' => 'sqlite']);
        $ref = new \ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}

final class TestBackupManager extends BackupManager
{
    public bool $rollbackCalled = false;

    protected function restoreInternal(string $file, array $metadata, array $options, bool $recovery): array
    {
        if ($recovery) {
            $this->rollbackCalled = true;
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'restore_failed'];
    }
}
