<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INT NULL,
  action VARCHAR(120) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id INT NULL,
  context TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(120) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id INT NULL,
  context TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_logs_user (user_id),
  INDEX idx_audit_logs_action (action),
  INDEX idx_audit_logs_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        }
        $pdo->exec($sql);
        if ($driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON audit_logs (user_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs (action)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs (entity)');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
    }
};
