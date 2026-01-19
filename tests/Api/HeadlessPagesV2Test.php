<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Modules\Api\Controller\PagesV2Controller;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('api')]
final class HeadlessPagesV2Test extends TestCase
{
    public function testListDefaultsToMinimalFields(): void
    {
        $db = $this->createDb();
        $controller = new PagesV2Controller($db);

        $request = new Request('GET', '/api/v2/pages', [], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, count($payload['data']['items']));
        $item = $payload['data']['items'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('slug', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('updated_at', $item);
        $this->assertArrayNotHasKey('content', $item);
        $this->assertArrayNotHasKey('blocks', $item);
    }

    public function testIncludeBlocksAndMedia(): void
    {
        $db = $this->createDb();
        $controller = new PagesV2Controller($db);

        $request = new Request('GET', '/api/v2/pages', [
            'include' => 'blocks,media',
        ], [], [], '');
        $response = $controller->index($request);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $item = $payload['data']['items'][0];
        $this->assertArrayHasKey('blocks', $item);
        $this->assertArrayHasKey('media', $item);
        $this->assertNotEmpty($item['blocks']);
        $this->assertNotEmpty($item['media']);
    }

    public function testShowAddsContentHtmlWhenBlocksMissingInCompatMode(): void
    {
        $db = $this->createDbWithLegacyContent();
        $controller = new PagesV2Controller($db);

        $request = new Request('GET', '/api/v2/pages/1', [
            'include' => 'blocks',
        ], [], [], '');
        $response = $controller->show($request, ['id' => 1]);

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['ok']);
        $page = $payload['data']['page'];
        $this->assertArrayHasKey('blocks', $page);
        $this->assertSame([], $page['blocks']);
        $this->assertSame('Legacy body', $page['content_html']);
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
        $pdo->exec('CREATE TABLE media_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            original_name TEXT,
            mime_type TEXT,
            size_bytes INTEGER,
            is_public INTEGER,
            created_at TEXT
        )');

        $pdo->exec("INSERT INTO pages (title, slug, content, status, created_at, updated_at) VALUES
            ('Hello', 'hello', 'Body', 'published', '2026-01-01', '2026-01-01'),
            ('Draft', 'draft', 'Body', 'draft', '2026-01-01', '2026-01-01')");

        $blocks = json_encode([
            ['type' => 'rich_text', 'data' => ['html' => '<p>Body</p>']],
            ['type' => 'image', 'data' => ['media_id' => 1, 'alt' => 'Alt']],
        ], JSON_UNESCAPED_SLASHES);
        $pdo->exec("INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by) VALUES
            (1, '" . $blocks . "', '2026-01-01', 1)");

        $pdo->exec("INSERT INTO media_files (original_name, mime_type, size_bytes, is_public, created_at) VALUES
            ('photo.jpg', 'image/jpeg', 12345, 1, '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }

    private function createDbWithLegacyContent(): DatabaseManager
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
            ('Legacy', 'legacy', 'Legacy body', 'published', '2026-01-01', '2026-01-01')");

        $db = new DatabaseManager(['driver' => 'mysql']);
        $ref = new ReflectionProperty($db, 'pdo');
        $ref->setAccessible(true);
        $ref->setValue($db, $pdo);

        return $db;
    }
}
