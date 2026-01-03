<?php
declare(strict_types=1);

use PDO;
use Throwable;

return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $indexes = [
            'idx_pages_title' => 'CREATE INDEX idx_pages_title ON pages (title)',
            'idx_pages_slug' => 'CREATE INDEX idx_pages_slug ON pages (slug)',
            'idx_media_files_original_name' => 'CREATE INDEX idx_media_files_original_name ON media_files (original_name)',
            'idx_media_files_mime_type' => 'CREATE INDEX idx_media_files_mime_type ON media_files (mime_type)',
            'idx_users_username' => 'CREATE INDEX idx_users_username ON users (username)',
            'idx_users_email' => 'CREATE INDEX idx_users_email ON users (email)',
        ];

        foreach ($indexes as $sql) {
            if ($driver === 'sqlite') {
                $sql = str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql);
                $pdo->exec($sql);
                continue;
            }

            try {
                $pdo->exec($sql);
            } catch (Throwable) {
                // ignore if already exists
            }
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $indexes = [
            'idx_pages_title',
            'idx_pages_slug',
            'idx_media_files_original_name',
            'idx_media_files_mime_type',
            'idx_users_username',
            'idx_users_email',
        ];

        foreach ($indexes as $name) {
            $sql = 'DROP INDEX ' . $name;
            if ($driver === 'sqlite') {
                $sql .= ' IF EXISTS';
            }

            try {
                $pdo->exec($sql);
            } catch (Throwable) {
                // ignore if missing
            }
        }
    }
};
