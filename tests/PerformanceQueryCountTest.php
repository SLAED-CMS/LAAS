<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Pages\Repository\PagesRepository;
use PHPUnit\Framework\TestCase;

final class PerformanceQueryCountTest extends TestCase
{
    public function testPagesListUsesSingleQuery(): void
    {
        $pdo = $this->createPdo();
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, status TEXT, content TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO pages (title, slug, status, content, updated_at) VALUES ('T','t','draft','x','2026-01-01 00:00:00')");

        $db = $this->wrapDb($pdo);
        $repo = new PagesRepository($db);

        $pdo->resetCount();
        $repo->listForAdmin(50, 0, '', 'all');
        $this->assertSame(1, $pdo->getCount());
    }

    public function testMediaListUsesSingleQuery(): void
    {
        $pdo = $this->createPdo();
        $pdo->exec('CREATE TABLE media_files (id INTEGER PRIMARY KEY AUTOINCREMENT, original_name TEXT, mime_type TEXT, disk_path TEXT, size_bytes INTEGER, created_at TEXT, uploaded_by INTEGER)');
        $pdo->exec("INSERT INTO media_files (original_name, mime_type, disk_path, size_bytes, created_at) VALUES ('a','image/png','x',10,'2026-01-01 00:00:00')");

        $db = $this->wrapDb($pdo);
        $repo = new MediaRepository($db);

        $pdo->resetCount();
        $repo->list(20, 0);
        $this->assertSame(1, $pdo->getCount());
    }

    public function testUsersListRolesBatchUsesTwoQueries(): void
    {
        $pdo = $this->createPdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, status INTEGER, last_login_at TEXT, last_login_ip TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, title TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE role_user (user_id INTEGER, role_id INTEGER)');
        $pdo->exec("INSERT INTO users (id, username, email) VALUES (1, 'u1', 'u1@example.com')");
        $pdo->exec("INSERT INTO users (id, username, email) VALUES (2, 'u2', 'u2@example.com')");
        $pdo->exec("INSERT INTO roles (id, name, title, created_at, updated_at) VALUES (1, 'admin', 'Admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO role_user (user_id, role_id) VALUES (1, 1)");

        $usersRepo = new UsersRepository($pdo);
        $rbacRepo = new RbacRepository($pdo);

        $pdo->resetCount();
        $users = $usersRepo->list(100, 0);
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $users);
        $rbacRepo->getRolesForUsers($ids);
        $this->assertSame(2, $pdo->getCount());
    }

    public function testAuditListUsesSingleQuery(): void
    {
        $pdo = $this->createPdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT)');
        $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, context TEXT, ip_address TEXT, created_at TEXT)');
        $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'admin')");
        $pdo->exec("INSERT INTO audit_logs (user_id, action, entity, created_at) VALUES (1, 'x', 'y', '2026-01-01 00:00:00')");

        $db = $this->wrapDb($pdo);
        $repo = new AuditLogRepository($db);

        $pdo->resetCount();
        $repo->list(50, 0);
        $this->assertSame(1, $pdo->getCount());
    }

    private function createPdo(): CountingPDO
    {
        return new CountingPDO('sqlite::memory:');
    }

    private function wrapDb(PDO $pdo): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);
        return $db;
    }
}

final class CountingPDO extends PDO
{
    private int $count = 0;

    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingPDOStatement::class, [$this]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->count++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->count++;
        return parent::exec($statement);
    }

    public function increment(): void
    {
        $this->count++;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function resetCount(): void
    {
        $this->count = 0;
    }
}

final class CountingPDOStatement extends PDOStatement
{
    private CountingPDO $counter;

    protected function __construct(CountingPDO $counter)
    {
        $this->counter = $counter;
    }

    public function execute(?array $params = null): bool
    {
        $this->counter->increment();
        return parent::execute($params);
    }
}
