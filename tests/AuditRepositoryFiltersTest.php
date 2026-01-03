<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use PHPUnit\Framework\TestCase;

final class AuditRepositoryFiltersTest extends TestCase
{
    public function testFiltersByUserActionAndDateRange(): void
    {
        $db = $this->createDatabase();
        $repo = new AuditLogRepository($db);

        $filters = [
            'user' => '1',
            'action' => 'media.upload',
            'from' => '2026-01-01',
            'to' => '2026-01-02',
        ];

        $rows = $repo->search($filters, 50, 0);
        $this->assertCount(1, $rows);
        $this->assertSame('media.upload', $rows[0]['action']);
    }

    private function createDatabase(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER NULL,
            context TEXT NULL,
            ip_address TEXT NULL,
            created_at TEXT NOT NULL
        )');

        $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'admin')");
        $pdo->exec("INSERT INTO audit_logs (user_id, action, entity, entity_id, context, ip_address, created_at)
            VALUES (1, 'media.upload', 'media', 10, NULL, '127.0.0.1', '2026-01-02 10:00:00')");
        $pdo->exec("INSERT INTO audit_logs (user_id, action, entity, entity_id, context, ip_address, created_at)
            VALUES (2, 'pages.update', 'page', 11, NULL, '127.0.0.1', '2026-01-03 10:00:00')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
