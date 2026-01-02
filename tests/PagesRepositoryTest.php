<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRepository;
use PHPUnit\Framework\TestCase;


final class PagesRepositoryTest extends TestCase
{
    public function testFindPublishedBySlug(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            slug TEXT UNIQUE,
            content TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new \ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        $repo = new PagesRepository($db);
        $repo->create([
            'title' => 'Test page',
            'slug' => 'test-page',
            'content' => 'Hello',
            'status' => 'published',
        ]);

        $page = $repo->findPublishedBySlug('test-page');
        $this->assertNotNull($page);
        $this->assertSame('Test page', $page['title']);
    }

    public function testListForAdminFiltersByQuery(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            slug TEXT UNIQUE,
            content TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES
            ('Alpha', 'alpha', '', 'published', '2025-01-01', '2025-01-01'),
            ('Beta', 'beta', '', 'draft', '2025-01-02', '2025-01-02')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new \ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        $repo = new PagesRepository($db);
        $rows = $repo->listForAdmin(100, 0, 'alp', 'all');

        $this->assertCount(1, $rows);
        $this->assertSame('alpha', $rows[0]['slug']);
    }
}
