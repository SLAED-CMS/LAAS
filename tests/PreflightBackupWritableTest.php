<?php
declare(strict_types=1);

use Laas\Ops\Checks\BackupWritableCheck;
use PHPUnit\Framework\TestCase;

final class PreflightBackupWritableTest extends TestCase
{
    public function testWarnsWhenBackupDirsMissing(): void
    {
        $root = sys_get_temp_dir() . '/laas_backup_check_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage', 0775, true);

        $check = new BackupWritableCheck($root);
        $result = $check->run();
        $this->assertSame(2, $result['code']);
    }

    public function testOkWhenBackupDirsWritable(): void
    {
        $root = sys_get_temp_dir() . '/laas_backup_check_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/backups', 0775, true);
        @mkdir($root . '/storage/tmp', 0775, true);

        $check = new BackupWritableCheck($root);
        $result = $check->run();
        $this->assertSame(0, $result['code']);
    }
}
