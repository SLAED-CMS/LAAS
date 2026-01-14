<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE security_reports ADD COLUMN status TEXT NOT NULL DEFAULT 'new'");
            $pdo->exec('ALTER TABLE security_reports ADD COLUMN updated_at DATETIME NULL');
            $pdo->exec('ALTER TABLE security_reports ADD COLUMN triaged_at DATETIME NULL');
            $pdo->exec('ALTER TABLE security_reports ADD COLUMN ignored_at DATETIME NULL');
            $pdo->exec("UPDATE security_reports SET status = 'new' WHERE status IS NULL OR status = ''");
            $pdo->exec('UPDATE security_reports SET updated_at = created_at WHERE updated_at IS NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_security_reports_status ON security_reports (status)');
            return;
        }

        $pdo->exec("ALTER TABLE security_reports ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'new'");
        $pdo->exec("ALTER TABLE security_reports ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
        $pdo->exec('ALTER TABLE security_reports ADD COLUMN triaged_at DATETIME NULL');
        $pdo->exec('ALTER TABLE security_reports ADD COLUMN ignored_at DATETIME NULL');
        $pdo->exec("UPDATE security_reports SET status = 'new' WHERE status IS NULL OR status = ''");
        $pdo->exec("UPDATE security_reports SET updated_at = created_at WHERE updated_at IS NULL OR updated_at = '1970-01-01 00:00:00'");
        $pdo->exec('CREATE INDEX idx_security_reports_status ON security_reports (status)');
    }

    public function down(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }

        $pdo->exec('DROP INDEX idx_security_reports_status ON security_reports');
        $pdo->exec('ALTER TABLE security_reports DROP COLUMN ignored_at');
        $pdo->exec('ALTER TABLE security_reports DROP COLUMN triaged_at');
        $pdo->exec('ALTER TABLE security_reports DROP COLUMN updated_at');
        $pdo->exec('ALTER TABLE security_reports DROP COLUMN status');
    }
};
