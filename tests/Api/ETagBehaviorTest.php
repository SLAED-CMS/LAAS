<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Api\Controller\PagesV2Controller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class ETagBehaviorTest extends TestCase
{
    public function testIfNoneMatchReturns304(): void
    {
        $db = $this->createDb();
        $controller = new PagesV2Controller($db);

        $request = new Request('GET', '/api/v2/pages/1', [], [], [], '');
        $response = $controller->show($request, ['id' => 1]);
        $etag = $response->getHeader('ETag');
        $this->assertNotNull($etag);

        $conditional = new Request('GET', '/api/v2/pages/1', [], [], [
            'if-none-match' => $etag,
        ], '');
        $notModified = $controller->show($conditional, ['id' => 1]);

        $this->assertSame(304, $notModified->getStatus());
        $this->assertSame('', $notModified->getBody());
        $this->assertSame('public, max-age=60', $notModified->getHeader('Cache-Control'));
        $this->assertSame($etag, $notModified->getHeader('ETag'));
        $this->assertSame('page:1', $notModified->getHeader('Surrogate-Key'));
    }

    private function createDb(): DatabaseManager
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            slug TEXT UNIQUE,
            content TEXT,
            status TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $pdo->exec('CREATE TABLE pages_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER,
            blocks_json TEXT,
            created_at TEXT,
            created_by INTEGER
        )');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES
            ('Hello', 'hello', 'Body', 'published', '2026-01-01', '2026-01-01')");
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES
            (1, '[]', '2026-01-01', 1)");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
