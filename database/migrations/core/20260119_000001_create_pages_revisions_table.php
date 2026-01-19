<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pages_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  page_id INTEGER NOT NULL,
  blocks_json TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  created_by INTEGER NULL
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pages_revisions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  page_id INT NOT NULL,
  blocks_json JSON NOT NULL,
  created_at DATETIME NOT NULL,
  created_by INT NULL,
  INDEX idx_pages_revisions_page_id (page_id),
  INDEX idx_pages_revisions_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        }
        $pdo->exec($sql);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS pages_revisions');
    }
};
