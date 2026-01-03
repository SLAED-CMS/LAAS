<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\StorageService;
use PDO;
use ZipArchive;

final class BackupManager
{
    public function __construct(
        private string $rootPath,
        private DatabaseManager $db,
        private StorageService $storage,
        private array $appConfig,
        private array $storageConfig
    ) {
    }

    /** @return array{ok: bool, file?: string, error?: string} */
    public function create(): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'zip_missing'];
        }

        $backupsDir = $this->rootPath . '/storage/backups';
        if (!is_dir($backupsDir) && !mkdir($backupsDir, 0775, true) && !is_dir($backupsDir)) {
            return ['ok' => false, 'error' => 'backup_dir_missing'];
        }

        $timestamp = gmdate('Ymd_His');
        $file = $backupsDir . '/backup_' . $timestamp . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'zip_open_failed'];
        }

        $version = (string) ($this->appConfig['version'] ?? 'unknown');
        $disk = (string) ($this->storageConfig['default'] ?? 'local');
        $metadata = [
            'version' => $version,
            'timestamp' => $timestamp,
            'disk' => $disk,
        ];
        $zip->addFromString('metadata.json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $dbDump = $this->dumpDatabase($this->db->pdo());
        $zip->addFromString('db.sql', $dbDump);

        $mediaRows = $this->fetchMediaRows($this->db->pdo());
        foreach ($mediaRows as $row) {
            $diskPath = (string) ($row['disk_path'] ?? '');
            if ($diskPath === '') {
                continue;
            }

            $tempPath = $this->storage->readToTemp($diskPath);
            if ($tempPath === null || !is_file($tempPath)) {
                $zip->close();
                @unlink($file);
                return ['ok' => false, 'error' => 'media_read_failed'];
            }

            $zipPath = 'media/' . ltrim(str_replace('\\', '/', $diskPath), '/');
            $zip->addFile($tempPath, $zipPath);

            if ($this->storage->driverName() !== 'local') {
                @unlink($tempPath);
            }
        }

        $zip->close();

        return ['ok' => true, 'file' => $file];
    }

    /** @return array{ok: bool, error?: string} */
    public function restore(string $file, bool $confirmed): array
    {
        if (!$confirmed) {
            return ['ok' => false, 'error' => 'confirm_required'];
        }

        if (!is_file($file)) {
            return ['ok' => false, 'error' => 'file_missing'];
        }

        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'error' => 'zip_missing'];
        }

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return ['ok' => false, 'error' => 'zip_open_failed'];
        }

        $metaRaw = $zip->getFromName('metadata.json');
        $metadata = $metaRaw !== false ? json_decode((string) $metaRaw, true) : null;
        $metaVersion = is_array($metadata) ? (string) ($metadata['version'] ?? '') : '';
        $currentVersion = (string) ($this->appConfig['version'] ?? 'unknown');
        if (!$this->isCompatibleVersion($metaVersion, $currentVersion)) {
            $zip->close();
            return ['ok' => false, 'error' => 'incompatible_version'];
        }

        $sql = $zip->getFromName('db.sql');
        if ($sql === false) {
            $zip->close();
            return ['ok' => false, 'error' => 'db_dump_missing'];
        }

        if (!$this->restoreDatabase($this->db->pdo(), (string) $sql)) {
            $zip->close();
            return ['ok' => false, 'error' => 'db_restore_failed'];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            if (!is_string($name) || !str_starts_with($name, 'media/')) {
                continue;
            }
            $diskPath = ltrim(substr($name, strlen('media/')), '/');
            if ($diskPath === '') {
                continue;
            }

            $tmp = $this->tempFile();
            $in = $zip->getStream($name);
            if ($in === false) {
                $zip->close();
                return ['ok' => false, 'error' => 'media_read_failed'];
            }

            $out = fopen($tmp, 'wb');
            if ($out === false) {
                if (is_resource($in)) {
                    fclose($in);
                }
                $zip->close();
                return ['ok' => false, 'error' => 'media_write_failed'];
            }

            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }

            if (!$this->storage->put($diskPath, $tmp)) {
                @unlink($tmp);
                $zip->close();
                return ['ok' => false, 'error' => 'media_write_failed'];
            }

            @unlink($tmp);
        }

        $zip->close();
        return ['ok' => true];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchMediaRows(PDO $pdo): array
    {
        if (!$this->tableExists($pdo, 'media_files')) {
            return [];
        }

        $stmt = $pdo->query('SELECT disk_path FROM media_files');
        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function dumpDatabase(PDO $pdo): string
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = $this->listTables($pdo, $driver);
        $lines = [];

        if ($driver === 'mysql') {
            $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
        } elseif ($driver === 'sqlite') {
            $lines[] = 'PRAGMA foreign_keys=OFF;';
        }

        foreach ($tables as $table) {
            $create = $this->getCreateStatement($pdo, $driver, $table);
            if ($create === '') {
                continue;
            }
            $lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $lines[] = $create . ';';

            $rows = $pdo->query('SELECT * FROM `' . $table . '`');
            if ($rows === false) {
                continue;
            }
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $this->quoteValue($pdo, $row[$column]);
                }
                $lines[] = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $values) . ');';
            }
        }

        if ($driver === 'mysql') {
            $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        } elseif ($driver === 'sqlite') {
            $lines[] = 'PRAGMA foreign_keys=ON;';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return array<int, string> */
    private function listTables(PDO $pdo, string $driver): array
    {
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return array_map(static fn ($row): string => (string) ($row['name'] ?? ''), $rows);
        }

        $stmt = $pdo->query('SHOW TABLES');
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_NUM) ?: [];
        $tables = [];
        foreach ($rows as $row) {
            if (isset($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
        return $tables;
    }

    private function getCreateStatement(PDO $pdo, string $driver, string $table): string
    {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=:name");
            if ($stmt && $stmt->execute(['name' => $table])) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                return (string) ($row['sql'] ?? '');
            }
            return '';
        }

        $stmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
        if ($stmt === false) {
            return '';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $create = $row['Create Table'] ?? $row['Create View'] ?? null;
        return is_string($create) ? $create : '';
    }

    private function quoteValue(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $pdo->quote((string) $value) ?: "''";
    }

    private function restoreDatabase(PDO $pdo, string $sql): bool
    {
        $statements = $this->splitSql($sql);
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if ($trimmed === '') {
                    continue;
                }
                $pdo->exec($trimmed);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    private function splitSql(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
        $statements = [];
        $buffer = '';
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(trim($line), ';')) {
                $statements[] = $buffer;
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }
        return $statements;
    }

    private function isCompatibleVersion(string $backup, string $current): bool
    {
        $backupMajor = $this->majorVersion($backup);
        $currentMajor = $this->majorVersion($current);
        if ($backupMajor === null || $currentMajor === null) {
            return false;
        }

        return $backupMajor === $currentMajor;
    }

    private function majorVersion(string $version): ?int
    {
        if (!preg_match('/v?(\\d+)\\./', $version, $matches)) {
            return null;
        }
        return (int) $matches[1];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:name");
            return $stmt !== false && $stmt->execute(['name' => $table]) && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        }

        $stmt = $pdo->prepare('SHOW TABLES LIKE :name');
        return $stmt !== false && $stmt->execute(['name' => $table]) && $stmt->fetch(PDO::FETCH_NUM) !== false;
    }

    private function tempFile(): string
    {
        $dir = sys_get_temp_dir();
        return $dir . '/laas_backup_' . bin2hex(random_bytes(8)) . '.tmp';
    }
}
