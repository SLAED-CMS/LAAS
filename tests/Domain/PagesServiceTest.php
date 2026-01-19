<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Pages\PagesService;
use PHPUnit\Framework\TestCase;

final class PagesServiceTest extends TestCase
{
    public function testCreateProducesPage(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db);

        $page = $service->create([
            'title' => 'Hello',
            'slug' => 'hello',
            'content' => 'Hello world',
            'status' => 'draft',
        ]);

        $this->assertSame('Hello', $page['title'] ?? null);
        $this->assertSame('hello', $page['slug'] ?? null);
        $this->assertNotEmpty($page['id'] ?? null);
    }

    public function testFindReturnsPageOrNull(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db);
        $page = $service->create([
            'title' => 'Find Me',
            'slug' => 'find-me',
            'content' => 'Text',
            'status' => 'draft',
        ]);

        $found = $service->find((int) ($page['id'] ?? 0));
        $this->assertNotNull($found);
        $this->assertSame('Find Me', $found['title'] ?? null);
        $this->assertNull($service->find(99999));
    }

    public function testListReturnsArray(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db);
        $service->create([
            'title' => 'First',
            'slug' => 'first',
            'content' => '',
            'status' => 'draft',
        ]);
        $service->create([
            'title' => 'Second',
            'slug' => 'second',
            'content' => '',
            'status' => 'published',
        ]);

        $pages = $service->list();

        $this->assertCount(2, $pages);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                slug TEXT,
                content TEXT,
                status TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        return $db;
    }
}
