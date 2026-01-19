<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Modules\Pages\Repository\PagesRevisionsRepository;
use PHPUnit\Framework\TestCase;

final class PageRevisionPersistenceTest extends TestCase
{
    public function testLatestRevisionIsReturned(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, content TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE pages_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, blocks_json TEXT, created_at TEXT, created_by INTEGER)');
        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES ('Hello', 'hello', 'Body', 'draft', '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        $repo = new PagesRevisionsRepository($db);
        $repo->createRevision(1, [
            ['type' => 'rich_text', 'data' => ['html' => '<p>One</p>']],
        ], 1);
        $repo->createRevision(1, [
            ['type' => 'rich_text', 'data' => ['html' => '<p>Two</p>']],
        ], 2);

        $latest = $repo->findLatestBlocksByPageId(1);
        $this->assertIsArray($latest);
        $this->assertSame('Two', strip_tags($latest[0]['data']['html'] ?? ''));
    }
}
