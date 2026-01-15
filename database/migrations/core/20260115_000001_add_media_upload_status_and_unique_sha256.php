<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $dupStmt = $pdo->query('SELECT sha256, COUNT(*) AS cnt FROM media_files WHERE sha256 IS NOT NULL GROUP BY sha256 HAVING cnt > 1 LIMIT 1');
        $dupRow = $dupStmt !== false ? $dupStmt->fetch(\PDO::FETCH_ASSOC) : null;
        if (is_array($dupRow) && !empty($dupRow['sha256'])) {
            throw new RuntimeException('Duplicate sha256 values found in media_files. Resolve duplicates before applying migration.');
        }

        $pdo->exec("ALTER TABLE media_files ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'ready'");
        $pdo->exec('ALTER TABLE media_files ADD COLUMN quarantine_path VARCHAR(255) NULL');

        try {
            $pdo->exec('DROP INDEX idx_media_files_sha256 ON media_files');
        } catch (\Throwable) {
            // ignore if missing
        }

        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = 'CREATE UNIQUE INDEX idx_media_files_sha256 ON media_files (sha256)';
        if ($driver === 'sqlite') {
            $sql = 'CREATE UNIQUE INDEX IF NOT EXISTS idx_media_files_sha256 ON media_files (sha256)';
        }
        $pdo->exec($sql);
    }

    public function down(\PDO $pdo): void
    {
        try {
            $pdo->exec('DROP INDEX idx_media_files_sha256 ON media_files');
        } catch (\Throwable) {
            // ignore if missing
        }

        try {
            $pdo->exec('ALTER TABLE media_files DROP COLUMN quarantine_path');
        } catch (\Throwable) {
            // ignore if missing
        }

        try {
            $pdo->exec('ALTER TABLE media_files DROP COLUMN status');
        } catch (\Throwable) {
            // ignore if missing
        }
    }
};
