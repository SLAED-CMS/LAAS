<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(190) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL
)
SQL;
        } else {
            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(190) NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    last_login_ip VARCHAR(45) NULL
)
SQL;
        }
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX idx_users_status ON users (status)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS users');
    }
};
