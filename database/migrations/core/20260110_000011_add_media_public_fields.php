<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE media_files ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE media_files ADD COLUMN public_token VARCHAR(64) NULL');
        $pdo->exec('CREATE INDEX idx_media_files_is_public ON media_files (is_public)');
        $pdo->exec('CREATE UNIQUE INDEX idx_media_files_public_token ON media_files (public_token)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP INDEX idx_media_files_public_token ON media_files');
        $pdo->exec('DROP INDEX idx_media_files_is_public ON media_files');
        $pdo->exec('ALTER TABLE media_files DROP COLUMN public_token');
        $pdo->exec('ALTER TABLE media_files DROP COLUMN is_public');
    }
};
