<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $indexes = [
            'idx_pages_status' => 'CREATE INDEX idx_pages_status ON pages (status)',
            'idx_media_files_sha256' => 'CREATE INDEX idx_media_files_sha256 ON media_files (sha256)',
            'idx_media_files_created_at' => 'CREATE INDEX idx_media_files_created_at ON media_files (created_at)',
            'idx_audit_logs_user_id' => 'CREATE INDEX idx_audit_logs_user_id ON audit_logs (user_id)',
        ];

        foreach ($indexes as $sql) {
            $statement = $driver === 'sqlite'
                ? str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql)
                : $sql;
            try {
                $pdo->exec($statement);
            } catch (\Throwable) {
                // ignore if exists
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $indexes = [
            'idx_pages_status',
            'idx_media_files_sha256',
            'idx_media_files_created_at',
            'idx_audit_logs_user_id',
        ];

        foreach ($indexes as $index) {
            $sql = 'DROP INDEX ' . $index;
            if ($driver === 'sqlite') {
                $sql .= ' IF EXISTS';
            }
            try {
                $pdo->exec($sql);
            } catch (\Throwable) {
                // ignore if missing
            }
        }
    }
};
