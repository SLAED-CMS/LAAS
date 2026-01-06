<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        if ($this->hasColumn($pdo, 'api_tokens', 'revoked_at')) {
            return;
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE api_tokens ADD COLUMN revoked_at DATETIME NULL');
            return;
        }

        $pdo->exec('ALTER TABLE api_tokens ADD COLUMN revoked_at DATETIME NULL AFTER expires_at');
    }

    public function down(\PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'api_tokens', 'revoked_at')) {
            return;
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            try {
                $pdo->exec('ALTER TABLE api_tokens DROP COLUMN revoked_at');
            } catch (\Throwable) {
                // ignore if sqlite version does not support drop column
            }
            return;
        }

        $pdo->exec('ALTER TABLE api_tokens DROP COLUMN revoked_at');
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
            if ($stmt !== false && $stmt->execute()) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    if (isset($row['name']) && strtolower((string) $row['name']) === strtolower($column)) {
                        return true;
                    }
                }
            }
            return false;
        }

        $quoted = $pdo->quote($column);
        $sql = 'SHOW COLUMNS FROM ' . $table . ' LIKE ' . $quoted;
        $stmt = $pdo->query($sql);
        $row = $stmt !== false ? $stmt->fetch() : false;

        return $row !== false;
    }
};
