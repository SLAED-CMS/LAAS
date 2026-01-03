<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\UsersRepository;
use PHPUnit\Framework\TestCase;

final class UsersSearchRepositoryTest extends TestCase
{
    public function testSearchFiltersByUsernameAndEmail(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            email TEXT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_login_at TEXT NULL,
            last_login_ip TEXT NULL
        )');

        $pdo->exec("INSERT INTO users (username, email, status, created_at, updated_at)
            VALUES ('alice', 'alice@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (username, email, status, created_at, updated_at)
            VALUES ('bob', 'bob@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $repo = new UsersRepository($db->pdo());
        $byName = $repo->search('alice', 10, 0);
        $this->assertCount(1, $byName);
        $this->assertSame('alice', $byName[0]['username']);

        $byEmail = $repo->search('bob@example.com', 10, 0);
        $this->assertCount(1, $byEmail);
        $this->assertSame('bob', $byEmail[0]['username']);
    }

    public function testSearchEscapesWildcards(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            email TEXT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_login_at TEXT NULL,
            last_login_ip TEXT NULL
        )');

        $pdo->exec("INSERT INTO users (username, email, status, created_at, updated_at)
            VALUES ('al%ice', 'alice@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (username, email, status, created_at, updated_at)
            VALUES ('albert', 'albert@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $repo = new UsersRepository($db->pdo());
        $rows = $repo->search('al%ice', 10, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('al%ice', $rows[0]['username']);
    }
}
