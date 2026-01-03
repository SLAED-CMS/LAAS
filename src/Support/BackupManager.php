<?php
declare(strict_types=1);

namespace Laas\Support;

use DateTimeImmutable;
use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

class BackupManager
{
    private string $rootPath;
    private DatabaseManager $db;
    private StorageService $storage;
    private array $appConfig;
    private array $storageConfig;
    private ?LoggerInterface $logger;

    public function __construct(
        string $rootPath,
        DatabaseManager $db,
        StorageService $storage,
        array $appConfig,
        array $storageConfig,
        ?LoggerInterface $logger = null
    ) {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->db = $db;
        $this->storage = $storage;
        $this->appConfig = $appConfig;
        $this->storageConfig = $storageConfig;
        $this->logger = $logger;
    }

    /** @return array{ok: bool, file?: string, error?: string, driver?: string} */
    public function create(array $options = []): array
    {
        try {
            $backupDir = $this->backupsDir();
            if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                throw new RuntimeException('backup_dir_failed');
            }

            $timestamp = (new DateTimeImmutable('now'))->format('Y-m-d_His');
            $label = (string) ($options['label'] ?? 'backup');
            $file = $backupDir . '/' . $label . '_' . $timestamp . '.zip';

            $dbDriver = $this->selectDbDriver((string) ($options['db_driver'] ?? 'auto'));
            $dump = $this->dumpDatabase($dbDriver);

            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('zip_open_failed');
            }

            $manifest = [];
            $dbEntry = 'db/dump.sql';
            $zip->addFile($dump['file'], $dbEntry);
            $manifest[] = [
                'path' => $dbEntry,
                'sha256' => hash_file('sha256', $dump['file']) ?: '',
            ];

            $tempFiles = [];
            foreach ($this->storageFiles() as $entry => $sourcePath) {
                $zip->addFile($sourcePath, $entry);
                $manifest[] = [
                    'path' => $entry,
                    'sha256' => hash_file('sha256', $sourcePath) ?: '',
                ];
                if ($this->isTempPath($sourcePath)) {
                    $tempFiles[] = $sourcePath;
                }
            }

            usort($manifest, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($manifestJson === false) {
                throw new RuntimeException('manifest_encode_failed');
            }
            $zip->addFromString('manifest.json', $manifestJson);

            $metadata = [
                'version' => (string) ($this->appConfig['version'] ?? 'v0.0.0'),
                'timestamp' => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM),
                'app_env' => (string) ($this->appConfig['env'] ?? 'dev'),
                'storage_disk' => $this->storageDisk(),
                'db_driver_used' => $dump['driver'],
                'checksum_db' => hash_file('sha256', $dump['file']) ?: '',
                'checksum_manifest' => hash('sha256', $manifestJson),
            ];

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($metadataJson === false) {
                throw new RuntimeException('metadata_encode_failed');
            }
            $zip->addFromString('metadata.json', $metadataJson);
            $zip->close();

            @unlink($dump['file']);
            foreach ($tempFiles as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }

            return [
                'ok' => true,
                'file' => $file,
                'driver' => $dump['driver'],
            ];
        } catch (Throwable $e) {
            $this->logError('backup:create', (string) $e->getMessage(), ['exception' => get_class($e)]);
            return ['ok' => false, 'error' => 'backup_failed'];
        }
    }

    /** @return array{ok: bool, metadata?: array, checks?: array, errors?: array, top?: array} */
    public function inspect(string $file): array
    {
        try {
            if (!is_file($file)) {
                return ['ok' => false, 'errors' => ['file_missing']];
            }

            $zip = new ZipArchive();
            if ($zip->open($file) !== true) {
                return ['ok' => false, 'errors' => ['zip_open_failed']];
            }

            $metadataRaw = $zip->getFromName('metadata.json');
            $manifestRaw = $zip->getFromName('manifest.json');
            if ($metadataRaw === false || $manifestRaw === false) {
                $zip->close();
                return ['ok' => false, 'errors' => ['metadata_missing']];
            }

            $metadata = json_decode((string) $metadataRaw, true);
            $manifest = json_decode((string) $manifestRaw, true);
            if (!is_array($metadata) || !is_array($manifest)) {
                $zip->close();
                return ['ok' => false, 'errors' => ['metadata_invalid']];
            }

            $errors = [];
            $checks = [
                'manifest' => hash('sha256', (string) $manifestRaw) === (string) ($metadata['checksum_manifest'] ?? ''),
                'db' => false,
                'entries' => true,
            ];

            foreach ($manifest as $entry) {
                $path = (string) ($entry['path'] ?? '');
                $expected = (string) ($entry['sha256'] ?? '');
                if ($path === '' || $expected === '') {
                    $checks['entries'] = false;
                    continue;
                }
                $content = $zip->getFromName($path);
                if ($content === false) {
                    $checks['entries'] = false;
                    continue;
                }
                $hash = hash('sha256', (string) $content);
                if (!hash_equals($expected, $hash)) {
                    $checks['entries'] = false;
                }
                if ($path === 'db/dump.sql') {
                    $checks['db'] = hash_equals($expected, (string) ($metadata['checksum_db'] ?? ''));
                }
            }

            if (!$checks['manifest']) {
                $errors[] = 'manifest_checksum';
            }
            if (!$checks['db']) {
                $errors[] = 'db_checksum';
            }
            if (!$checks['entries']) {
                $errors[] = 'entries_checksum';
            }

            $top = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $name = (string) ($stat['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $parts = explode('/', $name);
                $topKey = $parts[0] ?? '';
                if ($topKey === '') {
                    continue;
                }
                $size = (int) ($stat['size'] ?? 0);
                $top[$topKey] = ($top[$topKey] ?? 0) + $size;
            }
            ksort($top);
            $zip->close();

            return [
                'ok' => $errors === [],
                'metadata' => $metadata,
                'checks' => $checks,
                'errors' => $errors,
                'top' => $top,
            ];
        } catch (Throwable $e) {
            $this->logError('backup:inspect', (string) $e->getMessage(), ['exception' => get_class($e)]);
            return ['ok' => false, 'errors' => ['inspect_failed']];
        }
    }

    /** @return array{ok: bool, error?: string} */
    public function restore(string $file, array $options = []): array
    {
        $confirm1 = (string) ($options['confirm1'] ?? '');
        $confirm2 = (string) ($options['confirm2'] ?? '');
        $skipConfirm = (bool) ($options['skip_confirm'] ?? false);
        if (!$skipConfirm && ($confirm1 !== 'RESTORE' || $confirm2 !== basename($file))) {
            return ['ok' => false, 'error' => 'confirm_failed'];
        }

        $force = (bool) ($options['force'] ?? false);
        if ($this->isProd() && !$force) {
            return ['ok' => false, 'error' => 'forbidden_in_prod'];
        }

        $lock = $this->acquireRestoreLock();
        if ($lock === null) {
            return ['ok' => false, 'error' => 'locked'];
        }

        try {
            $inspect = $this->inspect($file);
            if (!$inspect['ok']) {
                return ['ok' => false, 'error' => 'inspect_failed'];
            }

            $metadata = $inspect['metadata'] ?? [];
            if (!$this->isCompatibleVersion((string) ($metadata['version'] ?? ''))) {
                return ['ok' => false, 'error' => 'incompatible_version'];
            }

            if ((bool) ($options['dry_run'] ?? false)) {
                return ['ok' => true];
            }

            $safety = $this->create(['db_driver' => 'pdo', 'label' => 'safety']);
            if (!$safety['ok']) {
                return ['ok' => false, 'error' => 'safety_backup_failed'];
            }

            $result = $this->restoreInternal($file, $metadata, $options, false);
            if ($result['ok']) {
                return $result;
            }

            $safetyInspect = $this->inspect($safety['file']);
            $this->restoreInternal(
                $safety['file'],
                $safetyInspect['metadata'] ?? [],
                ['skip_confirm' => true, 'force' => true],
                true
            );
            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array{ok: bool, error?: string} */
    protected function restoreInternal(string $file, array $metadata, array $options, bool $recovery): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return ['ok' => false, 'error' => 'zip_open_failed'];
        }

        $sql = $zip->getFromName('db/dump.sql');
        if ($sql === false) {
            $zip->close();
            return ['ok' => false, 'error' => 'db_dump_missing'];
        }

        if (!$this->restoreDatabaseFromSql((string) $sql)) {
            $zip->close();
            return ['ok' => false, 'error' => 'db_restore_failed'];
        }

        $disk = (string) ($metadata['storage_disk'] ?? $this->storageDisk());
        $storageEntries = $this->storageEntriesFromZip($zip, $disk);
        $result = $this->restoreStorageFromZip($zip, $disk, $storageEntries);
        $zip->close();

        if (!$result['ok']) {
            return $result;
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    protected function restoreStorageFromZip(ZipArchive $zip, string $disk, array $entries): array
    {
        $temp = $this->tempDir('restore');
        if ($temp === '') {
            return ['ok' => false, 'error' => 'restore_temp_failed'];
        }

        $diskRoot = $temp . '/storage/' . $disk;
        foreach ($entries as $entry) {
            $content = $zip->getFromName($entry);
            if ($content === false) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'zip_entry_missing'];
            }

            $target = $diskRoot . '/' . $entry;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }
            file_put_contents($target, $content);
        }

        if ($disk === 'local') {
            $source = $diskRoot . '/uploads';
            $target = $this->rootPath . '/storage/uploads';
            $backup = $this->rootPath . '/storage/uploads.bak_' . bin2hex(random_bytes(4));

            if (!is_dir($source)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }

            if (is_dir($target) && !rename($target, $backup)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }

            if (!rename($source, $target)) {
                if (is_dir($backup)) {
                    @rename($backup, $target);
                }
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }

            $this->removeDir($backup);
            $this->removeDir($temp);
            return ['ok' => true];
        }

        $stage = 'restore_tmp/' . bin2hex(random_bytes(8));
        foreach ($entries as $entry) {
            $local = $diskRoot . '/' . $entry;
            $stagePath = $stage . '/' . $entry;
            if (!$this->storage->put($stagePath, $local)) {
                $this->removeDir($temp);
                $this->cleanupStage($stage, $entries);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }
        }

        foreach ($entries as $entry) {
            $local = $diskRoot . '/' . $entry;
            if (!$this->storage->put($entry, $local)) {
                $this->removeDir($temp);
                $this->cleanupStage($stage, $entries);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }
        }

        $this->cleanupStage($stage, $entries);
        $this->removeDir($temp);
        return ['ok' => true];
    }

    private function cleanupStage(string $stage, array $entries): void
    {
        foreach ($entries as $entry) {
            $this->storage->delete($stage . '/' . $entry);
        }
    }

    /** @return string[] */
    private function storageEntriesFromZip(ZipArchive $zip, string $disk): array
    {
        $entries = [];
        $prefix = 'storage/' . $disk . '/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            if (str_ends_with($relative, '/')) {
                continue;
            }
            $entries[] = $relative;
        }
        sort($entries);
        return $entries;
    }

    private function removeDir(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private function restoreDatabaseFromSql(string $sql): bool
    {
        try {
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            $statements = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }
                $pdo->exec($statement);
            }
            $pdo->commit();
            return true;
        } catch (Throwable) {
            try {
                $this->db->pdo()->rollBack();
            } catch (Throwable) {
            }
            return false;
        }
    }

    private function backupsDir(): string
    {
        return $this->rootPath . '/storage/backups';
    }

    private function tempDir(string $prefix): string
    {
        $base = $this->rootPath . '/storage/tmp';
        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            $base = sys_get_temp_dir();
        }

        $path = $base . '/laas_' . $prefix . '_' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            return '';
        }
        return $path;
    }

    /** @return array{file: string, driver: string} */
    private function dumpDatabase(string $driver): array
    {
        $tmp = $this->tempDir('db');
        if ($tmp === '') {
            throw new RuntimeException('db_dump_temp_failed');
        }
        $file = $tmp . '/dump.sql';

        if ($driver === 'mysqldump') {
            if ($this->mysqldump($file)) {
                return ['file' => $file, 'driver' => 'mysqldump'];
            }
            $driver = 'pdo';
        }

        $this->pdoDump($file);
        return ['file' => $file, 'driver' => 'pdo'];
    }

    private function selectDbDriver(string $requested): string
    {
        $requested = strtolower($requested);
        if (in_array($requested, ['mysqldump', 'pdo'], true)) {
            return $requested;
        }
        if ($requested !== 'auto') {
            return 'pdo';
        }

        if ($this->databaseDriver() !== 'mysql') {
            return 'pdo';
        }

        return $this->mysqldumpAvailable() ? 'mysqldump' : 'pdo';
    }

    private function mysqldumpAvailable(): bool
    {
        $cmd = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where mysqldump' : 'command -v mysqldump';
        $output = [];
        $code = 1;
        @exec($cmd, $output, $code);
        return $code === 0 && $output !== [];
    }

    private function mysqldump(string $file): bool
    {
        $cfg = $this->databaseConfig();
        $db = (string) ($cfg['database'] ?? '');
        if ($db === '') {
            return false;
        }

        $cmd = [
            'mysqldump',
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ((int) ($cfg['port'] ?? 3306)),
            '--user=' . ($cfg['username'] ?? ''),
            '--single-transaction',
            '--skip-comments',
            '--skip-add-locks',
            '--skip-disable-keys',
            '--databases',
            $db,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $_ENV;
        $env['MYSQL_PWD'] = (string) ($cfg['password'] ?? '');
        $process = @proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);
        if ($status !== 0 || $out === false || $out === '') {
            $this->logError('backup:mysqldump', (string) $err, []);
            return false;
        }

        return file_put_contents($file, $out) !== false;
    }

    private function pdoDump(string $file): void
    {
        $pdo = $this->db->pdo();
        $driver = $this->databaseDriver();

        $tables = [];
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            $tables = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        } else {
            $stmt = $pdo->query('SHOW TABLES');
            $tables = $stmt ? array_map('current', $stmt->fetchAll()) : [];
            sort($tables);
        }

        $sql = [];
        $sql[] = 'BEGIN;';
        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table === '') {
                continue;
            }
            $sql[] = 'DROP TABLE IF EXISTS `' . $table . '`;';

            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
                $stmt->execute([$table]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!empty($row['sql'])) {
                    $sql[] = $row['sql'] . ';';
                }
            } else {
                $stmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
                $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
                if (is_array($row)) {
                    $create = $row['Create Table'] ?? null;
                    if (is_string($create) && $create !== '') {
                        $sql[] = $create . ';';
                    }
                }
            }

            $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
            if ($stmt === false) {
                continue;
            }

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $orderBy = $columns[0] ?? null;
            if ($orderBy !== null) {
                $stmt = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY `' . $orderBy . '`');
                $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : $rows;
            }

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (is_numeric($value)) {
                        $values[] = (string) $value;
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                $sql[] = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', $values) . ');';
            }
        }
        $sql[] = 'COMMIT;';

        file_put_contents($file, implode("\n", $sql));
    }

    private function databaseDriver(): string
    {
        try {
            $driver = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return is_string($driver) && $driver !== '' ? $driver : 'mysql';
        } catch (Throwable) {
            $config = $this->databaseConfig();
            return (string) ($config['driver'] ?? 'mysql');
        }
    }

    private function databaseConfig(): array
    {
        $path = $this->rootPath . '/config/database.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function storageDisk(): string
    {
        $disk = (string) ($this->storageConfig['default'] ?? $this->storage->driverName());
        return in_array($disk, ['local', 's3'], true) ? $disk : 'local';
    }

    /** @return array<string, string> */
    private function storageFiles(): array
    {
        $disk = $this->storageDisk();
        if ($disk === 'local') {
            $root = $this->rootPath . '/storage/uploads';
            return $this->localStorageFiles($root, 'storage/' . $disk . '/');
        }

        return $this->s3StorageFiles($disk);
    }

    /** @return array<string, string> */
    private function localStorageFiles(string $root, string $prefix): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/\\');
            $entry = $prefix . 'uploads/' . str_replace('\\', '/', $relative);
            $result[$entry] = $file->getPathname();
        }

        ksort($result);
        return $result;
    }

    /** @return array<string, string> */
    private function s3StorageFiles(string $disk): array
    {
        $result = [];
        $pdo = $this->db->pdo();
        $rows = $pdo->query('SELECT disk_path, sha256, mime_type FROM media_files')?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

        $mediaConfig = $this->mediaConfig();
        $thumbs = new MediaThumbnailService($this->storage);
        foreach ($rows as $row) {
            $diskPath = (string) ($row['disk_path'] ?? '');
            if ($diskPath === '') {
                continue;
            }
            if ($this->storage->exists($diskPath)) {
                $entry = 'storage/' . $disk . '/' . $diskPath;
                $tmp = $this->storage->readToTemp($diskPath);
                if ($tmp !== null && is_file($tmp)) {
                    $result[$entry] = $tmp;
                }
            }

            $variants = $mediaConfig['thumb_variants'] ?? [];
            if (!is_array($variants)) {
                $variants = [];
            }
            foreach (array_keys($variants) as $variant) {
                if (!is_string($variant)) {
                    continue;
                }
                $thumb = $thumbs->resolveThumbPath($row, $variant, $mediaConfig);
                if ($thumb === null) {
                    continue;
                }
                $thumbPath = $thumb['disk_path'];
                if ($this->storage->exists($thumbPath)) {
                    $entry = 'storage/' . $disk . '/' . $thumbPath;
                    $tmp = $this->storage->readToTemp($thumbPath);
                    if ($tmp !== null && is_file($tmp)) {
                        $result[$entry] = $tmp;
                    }
                }
                $reasonPath = $thumbPath . '.reason';
                if ($this->storage->exists($reasonPath)) {
                    $entry = 'storage/' . $disk . '/' . $reasonPath;
                    $tmp = $this->storage->readToTemp($reasonPath);
                    if ($tmp !== null && is_file($tmp)) {
                        $result[$entry] = $tmp;
                    }
                }
            }
        }

        ksort($result);
        return $result;
    }

    private function mediaConfig(): array
    {
        $path = $this->rootPath . '/config/media.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function acquireRestoreLock()
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $path = $dir . '/.restore.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function isCompatibleVersion(string $version): bool
    {
        $current = (string) ($this->appConfig['version'] ?? '');
        $major = $this->majorVersion($version);
        $currentMajor = $this->majorVersion($current);
        return $major !== '' && $major === $currentMajor;
    }

    private function majorVersion(string $version): string
    {
        if ($version === '') {
            return '';
        }
        if (preg_match('/v(\\d+)/', $version, $m)) {
            return (string) $m[1];
        }
        return '';
    }

    private function isProd(): bool
    {
        return strtolower((string) ($this->appConfig['env'] ?? '')) === 'prod';
    }

    private function logError(string $command, string $reason, array $context): void
    {
        $context = array_merge(['command' => $command, 'reason' => $reason], $context);
        if ($this->logger !== null) {
            $this->logger->error('backup', $context);
            return;
        }
        error_log('backup: ' . json_encode($context));
    }

    private function isTempPath(string $path): bool
    {
        $storageTmp = $this->rootPath . '/storage/tmp/';
        $systemTmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $storageTmp) || str_starts_with($path, $systemTmp);
    }
}
