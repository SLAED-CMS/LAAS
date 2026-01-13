<?php
declare(strict_types=1);

namespace Laas\Ops\Checks;

final class BackupWritableCheck
{
    private string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
    }

    /** @return array{code: int, message: string} */
    public function run(): array
    {
        $messages = [];
        $code = 0;

        $backupsDir = $this->rootPath . '/storage/backups';
        $backupsOk = is_dir($backupsDir) && is_writable($backupsDir);
        $messages[] = 'backups dir: ' . ($backupsOk ? 'OK' : 'WARN');

        $tmpDir = $this->rootPath . '/storage/tmp';
        $tmpOk = is_dir($tmpDir) && is_writable($tmpDir);
        $messages[] = 'tmp dir: ' . ($tmpOk ? 'OK' : 'WARN');

        if (!$backupsOk || !$tmpOk) {
            $code = 2;
        }

        return [
            'code' => $code,
            'message' => implode("\n", $messages),
        ];
    }
}
