<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS media_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(36) NOT NULL UNIQUE,
  disk_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NULL,
  uploaded_by INT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $pdo->exec($sql);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS media_files');
    }
};
