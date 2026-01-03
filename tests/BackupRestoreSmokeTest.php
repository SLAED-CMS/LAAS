<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupRestoreSmokeTest extends TestCase
{
    public function testRestoreDryRunValidatesArchive(): void
    {
        $root = $this->tempRoot();
        $db = $this->createDatabase($root);
        $storage = new StorageService($root);
        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v2.0.0',
            'env' => 'dev',
        ], [
            'default' => 'local',
        ]);

        $result = $manager->create(['db_driver' => 'pdo', 'label' => 'test']);
        $this->assertTrue($result['ok']);

        $file = (string) ($result['file'] ?? '');
        $restore = $manager->restore($file, [
            'confirm1' => 'RESTORE',
            'confirm2' => basename($file),
            'dry_run' => true,
        ]);
        $this->assertTrue($restore['ok']);
    }

    public function testRestoreRefusesWithoutConfirmations(): void
    {
        $root = $this->tempRoot();
        $db = $this->createDatabase($root);
        $storage = new StorageService($root);
        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v2.0.0',
            'env' => 'dev',
        ], [
            'default' => 'local',
        ]);

        $result = $manager->create(['db_driver' => 'pdo', 'label' => 'test']);
        $file = (string) ($result['file'] ?? '');

        $restore = $manager->restore($file, [
            'confirm1' => 'NO',
            'confirm2' => 'NO',
        ]);
        $this->assertFalse($restore['ok']);
        $this->assertSame('confirm_failed', $restore['error']);
    }

    private function tempRoot(): string
    {
        $base = sys_get_temp_dir() . '/laas_release_' . bin2hex(random_bytes(4));
        mkdir($base . '/storage/backups', 0775, true);
        mkdir($base . '/storage/uploads', 0775, true);
        mkdir($base . '/storage/tmp', 0775, true);
        mkdir($base . '/storage/cache', 0775, true);
        file_put_contents($base . '/storage/uploads/sample.txt', 'ok');
        return $base;
    }

    private function createDatabase(string $root): DatabaseManager
    {
        $dbFile = $root . '/storage/tmp/test.sqlite';
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec("INSERT INTO sample (name) VALUES ('alpha')");

        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => $dbFile]);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
