<?php
declare(strict_types=1);

use PDO;

return new class {
    public function up(PDO $pdo): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS modules (
    `name` VARCHAR(100) PRIMARY KEY,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `version` VARCHAR(20) NULL,
    `installed_at` DATETIME NULL,
    `updated_at` DATETIME NOT NULL
)
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX idx_modules_enabled ON modules (enabled)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS modules');
    }
};
