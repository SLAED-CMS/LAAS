<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE api_tokens ADD COLUMN token_prefix TEXT NOT NULL DEFAULT ''");
            $pdo->exec('ALTER TABLE api_tokens ADD COLUMN scopes TEXT NULL');
            $pdo->exec('ALTER TABLE api_tokens ADD COLUMN updated_at DATETIME NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_expires_at ON api_tokens(expires_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_api_tokens_revoked_at ON api_tokens(revoked_at)');
            return;
        }

        $pdo->exec('ALTER TABLE api_tokens MODIFY name VARCHAR(120) NOT NULL');
        $pdo->exec("ALTER TABLE api_tokens ADD COLUMN token_prefix VARCHAR(16) NOT NULL DEFAULT ''");
        $pdo->exec('ALTER TABLE api_tokens ADD COLUMN scopes JSON NULL');
        $pdo->exec("ALTER TABLE api_tokens ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
        $pdo->exec('CREATE INDEX idx_api_tokens_expires_at ON api_tokens(expires_at)');
        $pdo->exec('CREATE INDEX idx_api_tokens_revoked_at ON api_tokens(revoked_at)');
    }

    public function down(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }

        $pdo->exec('DROP INDEX idx_api_tokens_expires_at ON api_tokens');
        $pdo->exec('DROP INDEX idx_api_tokens_revoked_at ON api_tokens');
        $pdo->exec('ALTER TABLE api_tokens DROP COLUMN token_prefix');
        $pdo->exec('ALTER TABLE api_tokens DROP COLUMN scopes');
        $pdo->exec('ALTER TABLE api_tokens DROP COLUMN updated_at');
        $pdo->exec('ALTER TABLE api_tokens MODIFY name VARCHAR(100) NOT NULL');
    }
};
