<?php
declare(strict_types=1);

namespace Laas\Database;

use PDO;

final class DbIndexInspector
{
    private string $driver;

    public function __construct(private PDO $pdo)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return array{ok: bool, missing: array<int, array<string, string>>, notes: array<int, string>} */
    public function auditRequired(): array
    {
        $missing = [];
        $notes = [];

        $this->checkMigrations($missing, $notes);
        $this->checkApiTokens($missing, $notes);
        $this->checkAuditLogs($missing, $notes);
        $this->checkMediaFiles($missing, $notes);
        $this->checkPages($missing, $notes);

        return [
            'ok' => $missing === [],
            'missing' => $missing,
            'notes' => $notes,
        ];
    }

    private function checkMigrations(array &$missing, array &$notes): void
    {
        if (!$this->tableExists('migrations')) {
            $notes[] = 'table_missing:migrations';
            $missing[] = [
                'table' => 'migrations',
                'index' => 'id_or_migration_unique',
                'type' => 'primary_or_unique',
                'column' => 'id|migration',
            ];
            return;
        }

        $hasPrimary = $this->hasPrimaryKey('migrations', 'id');
        $hasUniqueMigration = $this->hasIndex('migrations', 'migration', true);
        if (!$hasPrimary && !$hasUniqueMigration) {
            $missing[] = [
                'table' => 'migrations',
                'index' => 'id_or_migration_unique',
                'type' => 'primary_or_unique',
                'column' => 'id|migration',
            ];
        }
    }

    private function checkApiTokens(array &$missing, array &$notes): void
    {
        if (!$this->tableExists('api_tokens')) {
            $notes[] = 'table_missing:api_tokens';
            $missing[] = [
                'table' => 'api_tokens',
                'index' => 'token_hash_unique',
                'type' => 'unique',
                'column' => 'token_hash',
            ];
            $missing[] = [
                'table' => 'api_tokens',
                'index' => 'expires_at',
                'type' => 'index',
                'column' => 'expires_at',
            ];
            $missing[] = [
                'table' => 'api_tokens',
                'index' => 'revoked_at',
                'type' => 'index',
                'column' => 'revoked_at',
            ];
            $missing[] = [
                'table' => 'api_tokens',
                'index' => 'last_used_at',
                'type' => 'index',
                'column' => 'last_used_at',
            ];
            return;
        }

        $this->checkIndex('api_tokens', 'token_hash', 'token_hash_unique', true, $missing, $notes);
        $this->checkIndex('api_tokens', 'expires_at', 'expires_at', false, $missing, $notes);
        $this->checkIndex('api_tokens', 'revoked_at', 'revoked_at', false, $missing, $notes);
        $this->checkIndex('api_tokens', 'last_used_at', 'last_used_at', false, $missing, $notes);
    }

    private function checkAuditLogs(array &$missing, array &$notes): void
    {
        if (!$this->tableExists('audit_logs')) {
            $notes[] = 'table_missing:audit_logs';
            $missing[] = [
                'table' => 'audit_logs',
                'index' => 'created_at',
                'type' => 'index',
                'column' => 'created_at',
            ];
            return;
        }

        $this->checkIndex('audit_logs', 'created_at', 'created_at', false, $missing, $notes);
    }

    private function checkMediaFiles(array &$missing, array &$notes): void
    {
        if (!$this->tableExists('media_files')) {
            $notes[] = 'table_missing:media_files';
            $missing[] = [
                'table' => 'media_files',
                'index' => 'sha256',
                'type' => 'index',
                'column' => 'sha256',
            ];
            return;
        }

        $this->checkIndex('media_files', 'sha256', 'sha256', false, $missing, $notes);
    }

    private function checkPages(array &$missing, array &$notes): void
    {
        if (!$this->tableExists('pages')) {
            $notes[] = 'table_missing:pages';
            $missing[] = [
                'table' => 'pages',
                'index' => 'slug_unique',
                'type' => 'unique',
                'column' => 'slug',
            ];
            $missing[] = [
                'table' => 'pages',
                'index' => 'status',
                'type' => 'index',
                'column' => 'status',
            ];
            return;
        }

        $this->checkIndex('pages', 'slug', 'slug_unique', true, $missing, $notes);
        $this->checkIndex('pages', 'status', 'status', false, $missing, $notes);
    }

    private function checkIndex(string $table, string $column, string $indexName, bool $uniqueRequired, array &$missing, array &$notes): void
    {
        if (!$this->columnExists($table, $column)) {
            $notes[] = 'column_missing:' . $table . '.' . $column;
            $missing[] = [
                'table' => $table,
                'index' => $indexName,
                'type' => $uniqueRequired ? 'unique' : 'index',
                'column' => $column,
            ];
            return;
        }

        if (!$this->hasIndex($table, $column, $uniqueRequired)) {
            $missing[] = [
                'table' => $table,
                'index' => $indexName,
                'type' => $uniqueRequired ? 'unique' : 'index',
                'column' => $column,
            ];
        }
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
            $stmt->execute(['name' => $table]);
            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name');
        $stmt->execute(['name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->query("PRAGMA table_info('{$table}')");
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if ((string) ($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasPrimaryKey(string $table, string $column): bool
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->query("PRAGMA table_info('{$table}')");
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            foreach ($rows as $row) {
                if ((string) ($row['name'] ?? '') === $column && (int) ($row['pk'] ?? 0) === 1) {
                    return true;
                }
            }
            return false;
        }

        $indexes = $this->listIndexes($table);
        foreach ($indexes as $index) {
            if (($index['name'] ?? '') !== 'PRIMARY') {
                continue;
            }
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(string $table, string $column, bool $uniqueRequired): bool
    {
        $indexes = $this->listIndexes($table);
        foreach ($indexes as $index) {
            $unique = (bool) ($index['unique'] ?? false);
            if ($uniqueRequired && !$unique) {
                continue;
            }
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{name: string, unique: bool, columns: array<int, string>}> */
    private function listIndexes(string $table): array
    {
        if ($this->driver === 'sqlite') {
            $stmt = $this->pdo->query("PRAGMA index_list('{$table}')");
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            $indexes = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $info = $this->pdo->query("PRAGMA index_info('{$name}')");
                $cols = $info !== false ? $info->fetchAll() : [];
                $columns = [];
                foreach ($cols as $col) {
                    $colName = (string) ($col['name'] ?? '');
                    if ($colName !== '') {
                        $columns[] = $colName;
                    }
                }
                $indexes[] = [
                    'name' => $name,
                    'unique' => (bool) ($row['unique'] ?? false),
                    'columns' => $columns,
                ];
            }
            return $indexes;
        }

        $stmt = $this->pdo->query('SHOW INDEX FROM `' . $table . '`');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        $map = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($map[$name])) {
                $map[$name] = [
                    'name' => $name,
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'columns' => [],
                ];
            }
            $col = (string) ($row['Column_name'] ?? '');
            if ($col !== '') {
                $map[$name]['columns'][] = $col;
            }
        }

        return array_values($map);
    }
}
