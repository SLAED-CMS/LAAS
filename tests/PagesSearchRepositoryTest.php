<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRepository;
use PHPUnit\Framework\TestCase;

final class PagesSearchRepositoryTest extends TestCase
{
    public function testSearchOrdersPrefixBeforeContains(): void
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            content TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at)
            VALUES ('Hello', 'hello', 'Hello content', 'published', '2026-01-01 00:00:00', '2026-01-02 00:00:00')");
        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at)
            VALUES ('Say hello', 'say-hello', 'Say hello content', 'published', '2026-01-01 00:00:00', '2026-01-03 00:00:00')");
        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at)
            VALUES ('Hello Draft', 'hello-draft', 'Draft', 'draft', '2026-01-01 00:00:00', '2026-01-04 00:00:00')");

        $repo = new PagesRepository($db);
        $rows = $repo->search('he', 10, 0, 'published');

        $this->assertCount(2, $rows);
        $this->assertSame('Hello', $rows[0]['title']);
    }
}
