<?php

declare(strict_types=1);

namespace Laas\Support;

use DateTimeImmutable;
use DateTimeZone;
use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Phar;
use PharData;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

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
        $tempFiles = [];
        $tmpTar = '';
        $tmpGz = '';
        $tmpDir = '';

        try {
            $backupDir = $this->backupsDir();
            if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
                throw new RuntimeException('backup_dir_failed');
            }

            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd_His');
            $file = $backupDir . '/laas_backup_' . $timestamp . '_v2.tar.gz';

            $includeDb = array_key_exists('include_db', $options) ? (bool) $options['include_db'] : true;
            $includeMedia = array_key_exists('include_media', $options) ? (bool) $options['include_media'] : true;

            $tmpDir = $this->tempDir('backup');
            if ($tmpDir === '') {
                throw new RuntimeException('backup_temp_failed');
            }

            $files = [];
            $dbDriverUsed = null;

            if ($includeDb) {
                $dbDriver = $this->selectDbDriver((string) ($options['db_driver'] ?? 'auto'));
                $dump = $this->dumpDatabase($dbDriver);
                $dbGz = $tmpDir . '/db.sql.gz';
                if (!$this->gzipFile($dump['file'], $dbGz)) {
                    throw new RuntimeException('db_gzip_failed');
                }
                @unlink($dump['file']);
                $files['db.sql.gz'] = $dbGz;
                $tempFiles[] = $dbGz;
                $dbDriverUsed = $dump['driver'];
            }

            if ($includeMedia) {
                foreach ($this->mediaFiles() as $entry => $sourcePath) {
                    $files[$entry] = $sourcePath;
                    if ($this->isTempPath($sourcePath)) {
                        $tempFiles[] = $sourcePath;
                    }
                }
            }
            $metadata = $this->buildMetadata($includeDb, $includeMedia, $dbDriverUsed);
            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($metadataJson === false) {
                throw new RuntimeException('metadata_encode_failed');
            }
            $metadataPath = $tmpDir . '/metadata.json';
            file_put_contents($metadataPath, $metadataJson);
            $files['metadata.json'] = $metadataPath;
            $tempFiles[] = $metadataPath;

            $manifest = $this->buildManifest($files);
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($manifestJson === false) {
                throw new RuntimeException('manifest_encode_failed');
            }
            $manifestPath = $tmpDir . '/manifest.json';
            file_put_contents($manifestPath, $manifestJson);
            $tempFiles[] = $manifestPath;

            $tmpBase = $backupDir . '/.backup_' . bin2hex(random_bytes(6));
            $tmpTar = $tmpBase . '.tar';
            $tmpGz = $tmpBase . '.tar.gz';

            $tar = new PharData($tmpTar);
            foreach ($files as $path => $source) {
                $tar->addFile($source, $path);
            }
            $tar->addFile($manifestPath, 'manifest.json');
            $tar->compress(Phar::GZ);

            if (is_file($tmpTar)) {
                @unlink($tmpTar);
            }

            if (!is_file($tmpGz)) {
                throw new RuntimeException('backup_compress_failed');
            }

            if (!rename($tmpGz, $file)) {
                throw new RuntimeException('backup_rename_failed');
            }

            return [
                'ok' => true,
                'file' => $file,
                'driver' => $dbDriverUsed ?? 'pdo',
            ];
        } catch (Throwable $e) {
            $this->logError('backup:create', (string) $e->getMessage(), ['exception' => get_class($e)]);
            return ['ok' => false, 'error' => 'backup_failed'];
        } finally {
            foreach ($tempFiles as $temp) {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
            if ($tmpDir !== '' && is_dir($tmpDir)) {
                $this->removeDir($tmpDir);
            }
            if ($tmpTar !== '' && is_file($tmpTar)) {
                @unlink($tmpTar);
            }
            if ($tmpGz !== '' && is_file($tmpGz)) {
                @unlink($tmpGz);
            }
        }
    }
    /** @return array{ok: bool, metadata?: array, manifest?: array, errors?: array} */
    public function verify(string $file): array
    {
        try {
            if (!is_file($file)) {
                return ['ok' => false, 'errors' => ['file_missing']];
            }

            try {
                $archive = new PharData($file);
            } catch (Throwable) {
                return ['ok' => false, 'errors' => ['archive_open_failed']];
            }

            if (!$archive->offsetExists('metadata.json') || !$archive->offsetExists('manifest.json')) {
                return ['ok' => false, 'errors' => ['metadata_missing']];
            }

            $metadataRaw = $archive['metadata.json']->getContent();
            $manifestRaw = $archive['manifest.json']->getContent();
            $metadata = json_decode((string) $metadataRaw, true);
            $manifest = json_decode((string) $manifestRaw, true);
            if (!is_array($metadata) || !is_array($manifest)) {
                return ['ok' => false, 'errors' => ['metadata_invalid']];
            }

            $errors = [];
            $format = (string) ($metadata['format'] ?? '');
            if ($format !== 'laas-backup-v2') {
                $errors[] = 'format_invalid';
            }

            $files = $manifest['files'] ?? null;
            if (!is_array($files)) {
                $errors[] = 'manifest_invalid';
                return ['ok' => false, 'errors' => $errors];
            }

            $paths = [];
            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    $errors[] = 'manifest_entry_invalid';
                    continue;
                }

                $path = (string) ($entry['path'] ?? '');
                $expected = (string) ($entry['sha256'] ?? '');
                $size = $entry['size'] ?? null;

                if ($path === '' || $expected === '' || str_contains($path, '..')) {
                    $errors[] = 'manifest_entry_invalid';
                    continue;
                }

                $paths[$path] = true;

                if (!$archive->offsetExists($path)) {
                    $errors[] = 'entry_missing';
                    continue;
                }

                $actualSize = $archive[$path]->getSize();
                if ($size !== null && (int) $size !== (int) $actualSize) {
                    $errors[] = 'entry_size_mismatch';
                }

                $hash = $this->hashArchiveEntry($file, $path);
                if ($hash === '' || !hash_equals($expected, $hash)) {
                    $errors[] = 'entry_hash_mismatch';
                }
            }

            $includes = $metadata['includes'] ?? [];
            if (is_array($includes)) {
                if (!empty($includes['db']) && !isset($paths['db.sql.gz'])) {
                    $errors[] = 'db_missing';
                }
            }

            $archiveSha = (string) ($manifest['archive_sha256'] ?? '');
            if ($archiveSha !== '') {
                $actual = hash_file('sha256', $file) ?: '';
                if (!hash_equals($archiveSha, $actual)) {
                    $errors[] = 'archive_hash_mismatch';
                }
            }

            return [
                'ok' => $errors === [],
                'metadata' => $metadata,
                'manifest' => $manifest,
                'errors' => $errors,
            ];
        } catch (Throwable $e) {
            $this->logError('backup:verify', (string) $e->getMessage(), ['exception' => get_class($e)]);
            return ['ok' => false, 'errors' => ['verify_failed']];
        }
    }

    /** @return array{ok: bool, metadata?: array, manifest?: array, errors?: array, checks?: array} */
    public function inspect(string $file): array
    {
        $result = $this->verify($file);
        $checks = [
            'verify' => $result['ok'] ?? false,
        ];

        return [
            'ok' => $result['ok'] ?? false,
            'metadata' => $result['metadata'] ?? [],
            'manifest' => $result['manifest'] ?? [],
            'errors' => $result['errors'] ?? [],
            'checks' => $checks,
        ];
    }

    /** @return array{ok: bool, error?: string, plan?: array} */
    public function restore(string $file, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $confirm1 = (string) ($options['confirm1'] ?? '');
        $confirm2 = (string) ($options['confirm2'] ?? '');
        $skipConfirm = (bool) ($options['skip_confirm'] ?? false) || $dryRun;

        if (!$skipConfirm && ($confirm1 !== 'RESTORE' || $confirm2 !== basename($file))) {
            return ['ok' => false, 'error' => 'confirm_failed'];
        }

        $force = (bool) ($options['force'] ?? false);
        if ($this->isProd() && !$force) {
            return ['ok' => false, 'error' => 'forbidden_in_prod'];
        }

        $lock = null;
        if (!$dryRun) {
            $lock = $this->acquireRestoreLock();
            if ($lock === null) {
                return ['ok' => false, 'error' => 'locked'];
            }
        }

        try {
            $verify = $this->verify($file);
            if (!$verify['ok']) {
                return ['ok' => false, 'error' => 'verify_failed'];
            }

            $metadata = $verify['metadata'] ?? [];
            $version = (string) ($metadata['laas_version'] ?? '');
            if (!$force && !$this->isCompatibleVersion($version)) {
                return ['ok' => false, 'error' => 'incompatible_version'];
            }

            $plan = $this->buildRestorePlan($metadata, $verify['manifest'] ?? []);
            if ($dryRun) {
                return ['ok' => true, 'plan' => $plan];
            }

            $safety = $this->create(['db_driver' => 'pdo', 'include_db' => true, 'include_media' => false]);
            if (!$safety['ok']) {
                return ['ok' => false, 'error' => 'safety_backup_failed'];
            }

            $result = $this->restoreInternal($file, $metadata, $options, false);
            if ($result['ok']) {
                return $result;
            }

            $safetyVerify = $this->verify($safety['file']);
            $this->restoreInternal(
                $safety['file'],
                $safetyVerify['metadata'] ?? [],
                ['skip_confirm' => true, 'force' => true],
                true
            );
            return $result;
        } finally {
            if ($lock !== null) {
                $this->releaseLock($lock);
            }
        }
    }

    /** @return array{deleted: int} */
    public function prune(int $keep): array
    {
        $keep = max(0, $keep);
        $dir = $this->backupsDir();
        if (!is_dir($dir)) {
            return ['deleted' => 0];
        }

        $files = glob($dir . '/laas_backup_*_v2.tar.gz') ?: [];
        if ($files === []) {
            return ['deleted' => 0];
        }

        rsort($files, SORT_STRING);
        $deleted = 0;
        $remove = array_slice($files, $keep);
        foreach ($remove as $file) {
            if (is_file($file) && preg_match('/laas_backup_\d{8}_\d{6}_v2\.tar\.gz$/', basename($file))) {
                @unlink($file);
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }
    /** @return array{ok: bool, error?: string} */
    protected function restoreInternal(string $file, array $metadata, array $options, bool $recovery): array
    {
        try {
            $archive = new PharData($file);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'archive_open_failed'];
        }

        $includes = $metadata['includes'] ?? [];
        $includeDb = is_array($includes) ? !empty($includes['db']) : $archive->offsetExists('db.sql.gz');
        $includeMedia = is_array($includes) ? !empty($includes['media']) : $this->archiveHasMedia($file);

        if ($includeDb) {
            if (!$archive->offsetExists('db.sql.gz')) {
                return ['ok' => false, 'error' => 'db_dump_missing'];
            }

            $sqlGz = $archive['db.sql.gz']->getContent();
            $sql = $sqlGz !== '' ? @gzdecode((string) $sqlGz) : false;
            if ($sql === false) {
                return ['ok' => false, 'error' => 'db_dump_invalid'];
            }

            if (!$this->restoreDatabaseFromSql((string) $sql)) {
                return ['ok' => false, 'error' => 'db_restore_failed'];
            }
        }

        if ($includeMedia) {
            $disk = $this->restoreDisk($metadata);
            $entries = $this->mediaEntriesFromArchive($file);
            $result = $this->restoreMediaFromArchive($file, $disk, $entries);
            if (!$result['ok']) {
                return $result;
            }
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    protected function restoreMediaFromArchive(string $file, string $disk, array $entries): array
    {
        if ($entries === []) {
            return ['ok' => true];
        }

        $temp = $this->tempDir('restore');
        if ($temp === '') {
            return ['ok' => false, 'error' => 'restore_temp_failed'];
        }

        foreach ($entries as $entry) {
            $source = $this->archivePath($file, $entry);
            $target = $temp . '/' . $entry;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }

            if (!copy($source, $target)) {
                $this->removeDir($temp);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }
        }

        if ($disk === 'local') {
            $source = $temp . '/media/uploads';
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
            $relative = $this->stripMediaPrefix($entry);
            $local = $temp . '/' . $entry;
            $stagePath = $stage . '/' . $relative;
            if (!$this->storage->put($stagePath, $local)) {
                $this->removeDir($temp);
                $this->cleanupStage($stage, $entries);
                return ['ok' => false, 'error' => 'restore_storage_failed'];
            }
        }

        foreach ($entries as $entry) {
            $relative = $this->stripMediaPrefix($entry);
            $local = $temp . '/' . $entry;
            if (!$this->storage->put($relative, $local)) {
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
            $relative = $this->stripMediaPrefix($entry);
            $this->storage->delete($stage . '/' . $relative);
        }
    }

    /** @return string[] */
    private function mediaEntriesFromArchive(string $file): array
    {
        $entries = [];
        $base = $this->archiveBase($file) . '/media';
        if (!is_dir($base)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            $prefix = $this->archiveBase($file) . '/';
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $entries[] = $relative;
        }

        sort($entries);
        return $entries;
    }

    private function archiveHasMedia(string $file): bool
    {
        $base = $this->archiveBase($file) . '/media';
        return is_dir($base);
    }

    private function stripMediaPrefix(string $entry): string
    {
        if (str_starts_with($entry, 'media/')) {
            return substr($entry, strlen('media/'));
        }
        return $entry;
    }
    private function buildRestorePlan(array $metadata, array $manifest): array
    {
        $includes = $metadata['includes'] ?? [];
        $files = $manifest['files'] ?? [];
        $hasDb = false;
        $hasMedia = false;

        if (is_array($files)) {
            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = (string) ($entry['path'] ?? '');
                if ($path === 'db.sql.gz') {
                    $hasDb = true;
                }
                if (str_starts_with($path, 'media/')) {
                    $hasMedia = true;
                }
            }
        }

        $includeDb = is_array($includes) ? !empty($includes['db']) : $hasDb;
        $includeMedia = is_array($includes) ? !empty($includes['media']) : $hasMedia;

        $dbName = $this->databaseName();
        $dbTarget = $this->databaseDriver() . ($dbName !== '' ? (':' . $dbName) : '');

        $disk = $this->restoreDisk($metadata);
        $mediaTarget = $disk === 'local'
            ? ($this->rootPath . '/storage/uploads')
            : 's3';

        return [
            'db' => $includeDb,
            'media' => $includeMedia,
            'targets' => [
                'db' => $dbTarget,
                'media' => $mediaTarget,
            ],
        ];
    }

    private function restoreDisk(array $metadata): string
    {
        $storage = $metadata['storage'] ?? [];
        if (is_array($storage)) {
            $disk = (string) ($storage['disk'] ?? '');
            if (in_array($disk, ['local', 's3'], true)) {
                return $disk;
            }
        }

        return $this->storageDisk();
    }

    private function buildMetadata(bool $includeDb, bool $includeMedia, ?string $dbDriverUsed): array
    {
        $dbName = $this->databaseName();
        $counts = $this->collectCounts();
        $driver = $this->databaseDriver();

        return [
            'format' => 'laas-backup-v2',
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'laas_version' => (string) ($this->appConfig['version'] ?? 'v0.0.0'),
            'php' => PHP_VERSION,
            'db' => [
                'driver' => $dbDriverUsed ?? $driver,
                'name' => $dbName,
            ],
            'storage' => [
                'disk' => $this->storageDisk(),
                'mode' => 'export',
            ],
            'counts' => $counts,
            'includes' => [
                'db' => $includeDb,
                'media' => $includeMedia,
            ],
        ];
    }

    /** @param array<string, string> $files */
    private function buildManifest(array $files): array
    {
        $entries = [];
        foreach ($files as $path => $source) {
            if ($path === '' || !is_file($source)) {
                continue;
            }
            $entries[] = [
                'path' => $path,
                'sha256' => hash_file('sha256', $source) ?: '',
                'size' => filesize($source) ?: 0,
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return [
            'files' => $entries,
        ];
    }

    private function collectCounts(): array
    {
        $counts = [];
        try {
            $pdo = $this->db->pdo();
        } catch (Throwable) {
            return $counts;
        }

        $tables = [
            'media_files' => 'media_files',
            'pages' => 'pages',
        ];

        foreach ($tables as $key => $table) {
            $value = $this->countTable($pdo, $table);
            if ($value !== null) {
                $counts[$key] = $value;
            }
        }

        return $counts;
    }

    private function countTable(\PDO $pdo, string $table): ?int
    {
        try {
            $stmt = $pdo->query('SELECT COUNT(*) AS c FROM ' . $table);
            $count = $stmt ? $stmt->fetchColumn() : null;
            return is_numeric($count) ? (int) $count : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseName(): string
    {
        $config = $this->databaseConfig();
        $db = (string) ($config['database'] ?? $config['name'] ?? '');
        if ($db === '') {
            return '';
        }
        if (str_contains($db, '/') || str_contains($db, '\\')) {
            return basename($db);
        }
        return $db;
    }

    private function archiveBase(string $file): string
    {
        return str_replace('\\', '/', 'phar://' . $file);
    }

    private function archivePath(string $file, string $entry): string
    {
        return $this->archiveBase($file) . '/' . ltrim($entry, '/');
    }

    private function hashArchiveEntry(string $file, string $path): string
    {
        $stream = fopen($this->archivePath($file, $path), 'rb');
        if ($stream === false) {
            return '';
        }

        $hash = hash_init('sha256');
        if (!hash_update_stream($hash, $stream)) {
            fclose($stream);
            return '';
        }
        fclose($stream);
        return (string) hash_final($hash);
    }

    private function gzipFile(string $source, string $target): bool
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            return false;
        }

        $out = gzopen($target, 'wb9');
        if ($out === false) {
            fclose($in);
            return false;
        }

        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                return false;
            }
            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);
        return true;
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
    private function mediaFiles(): array
    {
        $disk = $this->storageDisk();
        if ($disk === 'local') {
            $root = $this->rootPath . '/storage/uploads';
            return $this->localMediaFiles($root, 'media/');
        }

        return $this->s3MediaFiles($disk);
    }

    /** @return array<string, string> */
    private function localMediaFiles(string $root, string $prefix): array
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
    private function s3MediaFiles(string $disk): array
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
                $entry = 'media/' . ltrim($diskPath, '/');
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
                    $entry = 'media/' . ltrim($thumbPath, '/');
                    $tmp = $this->storage->readToTemp($thumbPath);
                    if ($tmp !== null && is_file($tmp)) {
                        $result[$entry] = $tmp;
                    }
                }
                $reasonPath = $thumbPath . '.reason';
                if ($this->storage->exists($reasonPath)) {
                    $entry = 'media/' . ltrim($reasonPath, '/');
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
        if (preg_match('/v(\d+)/', $version, $m)) {
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
