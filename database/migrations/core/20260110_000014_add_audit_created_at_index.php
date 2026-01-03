<?php
declare(strict_types=1);

use PDO;
use Throwable;

return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = 'CREATE INDEX idx_audit_logs_created_at ON audit_logs (created_at)';

        if ($driver === 'sqlite') {
            $sql = str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql);
            $pdo->exec($sql);
            return;
        }

        try {
            $pdo->exec($sql);
        } catch (Throwable) {
            // ignore if exists
        }
    }

    public function down(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = 'DROP INDEX idx_audit_logs_created_at';
        if ($driver === 'sqlite') {
            $sql .= ' IF EXISTS';
        }

        try {
            $pdo->exec($sql);
        } catch (Throwable) {
            // ignore if missing
        }
    }
};
