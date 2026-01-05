<?php
declare(strict_types=1);

use Laas\Auth\AuthService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\UsersRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySession;

final class AuthServiceTest extends TestCase
{
    private InMemorySession $session;

    protected function setUp(): void
    {
        $this->session = new InMemorySession();
        $this->session->start();
    }

    public function testAttemptFailsWithBadPassword(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            last_login_ip TEXT NULL,
            updated_at TEXT NULL
        )');
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password_hash, status) VALUES ('admin', '{$hash}', 1)");

        $repo = new UsersRepository($db->pdo());
        $auth = new AuthService($repo, $this->session);

        $ok = $auth->attempt('admin', 'wrong', '127.0.0.1');

        $this->assertFalse($ok);
        $this->assertFalse($this->session->has('user_id'));
    }

    public function testAttemptFailsForDisabledUser(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            last_login_ip TEXT NULL,
            updated_at TEXT NULL
        )');
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password_hash, status) VALUES ('admin', '{$hash}', 0)");

        $repo = new UsersRepository($db->pdo());
        $auth = new AuthService($repo, $this->session);

        $ok = $auth->attempt('admin', 'secret', '127.0.0.1');

        $this->assertFalse($ok);
        $this->assertFalse($this->session->has('user_id'));
    }
}
