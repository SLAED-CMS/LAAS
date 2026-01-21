<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Api\Controller\PagesV2Controller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class HeadlessPagesV2ShapeTest extends TestCase
{
    public function testShowRespectsFieldsAndInclude(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesV2Controller(null, $service);

        $request = new Request('GET', '/api/v2/pages/1', [
            'fields' => 'slug,title',
            'include' => 'blocks',
        ], [], [], '');
        $response = $controller->show($request, ['id' => 1]);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $page = $payload['data']['page'];
        $this->assertSame('hello', $page['slug'] ?? null);
        $this->assertSame('Hello', $page['title'] ?? null);
        $this->assertArrayHasKey('blocks', $page);
        $this->assertNotEmpty($page['blocks']);
        $this->assertArrayNotHasKey('content', $page);
        $this->assertArrayNotHasKey('status', $page);
        $this->assertArrayNotHasKey('updated_at', $page);
        $this->assertArrayNotHasKey('media', $page);
    }

    public function testBySlugRespectsFieldsAndInclude(): void
    {
        $db = $this->createDb();
        $service = new \Laas\Domain\Pages\PagesService($db);
        $controller = new PagesV2Controller(null, $service);

        $request = new Request('GET', '/api/v2/pages/hello', [
            'fields' => 'slug,title',
            'include' => 'blocks',
        ], [], [], '');
        $response = $controller->bySlug($request, ['slug' => 'hello']);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $page = $payload['data']['page'];
        $this->assertSame('hello', $page['slug'] ?? null);
        $this->assertSame('Hello', $page['title'] ?? null);
        $this->assertArrayHasKey('blocks', $page);
        $this->assertNotEmpty($page['blocks']);
        $this->assertArrayNotHasKey('content', $page);
        $this->assertArrayNotHasKey('status', $page);
        $this->assertArrayNotHasKey('updated_at', $page);
        $this->assertArrayNotHasKey('media', $page);
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

        $blocks = json_encode([
            ['type' => 'rich_text', 'data' => ['html' => '<p>Body</p>']],
        ], JSON_UNESCAPED_SLASHES);
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES
            (1, '" . $blocks . "', '2026-01-01', 1)");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
