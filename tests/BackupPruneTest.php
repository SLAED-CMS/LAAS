<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupPruneTest extends TestCase
{
    public function testPruneKeepsLatest(): void
    {
        $root = sys_get_temp_dir() . '/laas_backup_prune_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/backups', 0775, true);

        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $storage = new StorageService($root);
        $manager = new BackupManager($root, $db, $storage, [
            'version' => 'v3.6.0',
            'env' => 'dev',
        ], [
            'default' => 'local',
        ]);

        $files = [
            $root . '/storage/backups/laas_backup_20240101_010101_v2.tar.gz',
            $root . '/storage/backups/laas_backup_20240102_010101_v2.tar.gz',
            $root . '/storage/backups/laas_backup_20240103_010101_v2.tar.gz',
            $root . '/storage/backups/other_backup.tar.gz',
        ];

        foreach ($files as $file) {
            file_put_contents($file, 'x');
        }

        $result = $manager->prune(1);
        $this->assertSame(2, $result['deleted']);
        $this->assertFileExists($files[2]);
        $this->assertFileExists($files[3]);
        $this->assertFileDoesNotExist($files[0]);
        $this->assertFileDoesNotExist($files[1]);
    }
}
