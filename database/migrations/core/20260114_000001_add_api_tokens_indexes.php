<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $indexes = [
            'idx_api_tokens_expires_at' => 'CREATE INDEX idx_api_tokens_expires_at ON api_tokens (expires_at)',
            'idx_api_tokens_revoked_at' => 'CREATE INDEX idx_api_tokens_revoked_at ON api_tokens (revoked_at)',
            'idx_api_tokens_last_used_at' => 'CREATE INDEX idx_api_tokens_last_used_at ON api_tokens (last_used_at)',
        ];

        foreach ($indexes as $sql) {
            $statement = $driver === 'sqlite'
                ? str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql)
                : $sql;
            try {
                $pdo->exec($statement);
            } catch (\Throwable) {
                // ignore if exists
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $indexes = [
            'idx_api_tokens_expires_at',
            'idx_api_tokens_revoked_at',
            'idx_api_tokens_last_used_at',
        ];

        foreach ($indexes as $index) {
            $sql = 'DROP INDEX ' . $index;
            if ($driver === 'sqlite') {
                $sql .= ' IF EXISTS';
            } else {
                $sql .= ' ON api_tokens';
            }
            try {
                $pdo->exec($sql);
            } catch (\Throwable) {
                // ignore if missing
            }
        }
    }
};
