<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRepository;
use PHPUnit\Framework\TestCase;

final class PagesSearchSqlInjectionTest extends TestCase
{
    public function testSearchEscapesWildcards(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            status TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            content TEXT NOT NULL
        )');

        $pdo->exec("INSERT INTO pages (title, slug, status, updated_at, content)
            VALUES ('100% legit', 'p1', 'published', '2026-01-01 00:00:00', 'x')");
        $pdo->exec("INSERT INTO pages (title, slug, status, updated_at, content)
            VALUES ('100X legit', 'p2', 'published', '2026-01-01 00:00:00', 'y')");

        $repo = new PagesRepository($db);
        $rows = $repo->search('100% legit', 10, 0, 'published');

        $this->assertCount(1, $rows);
        $this->assertSame('100% legit', $rows[0]['title']);
    }

    public function testSearchDoesNotBypassWithQuotes(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            status TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            content TEXT NOT NULL
        )');

        $pdo->exec("INSERT INTO pages (title, slug, status, updated_at, content)
            VALUES ('alpha', 'a', 'published', '2026-01-01 00:00:00', 'x')");
        $pdo->exec("INSERT INTO pages (title, slug, status, updated_at, content)
            VALUES ('beta', 'b', 'published', '2026-01-01 00:00:00', 'y')");

        $repo = new PagesRepository($db);
        $rows = $repo->search("' OR 1=1 --", 10, 0, 'published');

        $this->assertCount(0, $rows);
    }
}
