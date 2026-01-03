<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PerfIndexesMigrationTest extends TestCase
{
    public function testPerfIndexesMigrationAddsIndexes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE media_files (id INTEGER PRIMARY KEY AUTOINCREMENT, sha256 TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, created_at TEXT)');

        $migration = require dirname(__DIR__) . '/database/migrations/core/20260110_000017_add_perf_indexes.php';
        $migration->up($pdo);

        $this->assertTrue($this->hasIndex($pdo, 'pages', 'idx_pages_status'));
        $this->assertTrue($this->hasIndex($pdo, 'media_files', 'idx_media_files_sha256'));
        $this->assertTrue($this->hasIndex($pdo, 'media_files', 'idx_media_files_created_at'));
        $this->assertTrue($this->hasIndex($pdo, 'audit_logs', 'idx_audit_logs_user_id'));
    }

    private function hasIndex(PDO $pdo, string $table, string $name): bool
    {
        $stmt = $pdo->query("PRAGMA index_list('{$table}')");
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if ((string) ($row['name'] ?? '') === $name) {
                return true;
            }
        }
        return false;
    }
}
