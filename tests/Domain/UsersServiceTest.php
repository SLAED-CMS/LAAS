<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Users\UsersService;
use PHPUnit\Framework\TestCase;

final class UsersServiceTest extends TestCase
{
    public function testListReturnsUsersWithActiveFlag(): void
    {
        $db = $this->createDb();
        $service = new UsersService($db);

        $rows = $service->list();

        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) ($rows[0]['status'] ?? 0));
        $this->assertTrue($rows[0]['active'] ?? false);
        $this->assertFalse($rows[1]['active'] ?? true);
    }

    public function testRolesForUsersReturnsRoles(): void
    {
        $db = $this->createDb();
        $service = new UsersService($db);

        $roles = $service->rolesForUsers([1, 2]);

        $this->assertSame(['admin'], $roles[1] ?? []);
        $this->assertSame([], $roles[2] ?? []);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            email TEXT,
            password_hash TEXT,
            status INTEGER,
            last_login_at TEXT,
            last_login_ip TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            title TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');

        $pdo->exec("INSERT INTO users (id, username, email, password_hash, status, created_at) VALUES
            (1, 'admin', 'admin@example.com', 'hash', 1, '2026-01-01 00:00:00'),
            (2, 'user', 'user@example.com', 'hash', 0, '2026-01-02 00:00:00')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at) VALUES
            (1, 'admin', 'Admin', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        return $db;
    }
}
