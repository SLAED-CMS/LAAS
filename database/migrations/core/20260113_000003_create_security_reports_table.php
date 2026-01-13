<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS security_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  document_uri TEXT NOT NULL,
  violated_directive TEXT NOT NULL,
  blocked_uri TEXT NOT NULL,
  user_agent TEXT NOT NULL,
  ip TEXT NOT NULL,
  request_id TEXT NULL
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS security_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL,
  document_uri VARCHAR(255) NOT NULL,
  violated_directive VARCHAR(255) NOT NULL,
  blocked_uri VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  request_id VARCHAR(64) NULL,
  INDEX idx_security_reports_type (type),
  INDEX idx_security_reports_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        }

        $pdo->exec($sql);

        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_reports_type ON security_reports (type)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_reports_created_at ON security_reports (created_at)');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS security_reports');
    }
};
