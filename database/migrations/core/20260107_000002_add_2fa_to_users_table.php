<?php
declare(strict_types=1);

return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("
                ALTER TABLE users
                ADD COLUMN totp_secret VARCHAR(255) NULL AFTER password_hash,
                ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
                ADD COLUMN backup_codes TEXT NULL AFTER totp_enabled
            ");
        } elseif ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD COLUMN backup_codes TEXT NULL");
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("
                ALTER TABLE users
                DROP COLUMN backup_codes,
                DROP COLUMN totp_enabled,
                DROP COLUMN totp_secret
            ");
        } elseif ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE users DROP COLUMN backup_codes");
            $pdo->exec("ALTER TABLE users DROP COLUMN totp_enabled");
            $pdo->exec("ALTER TABLE users DROP COLUMN totp_secret");
        }
    }
};