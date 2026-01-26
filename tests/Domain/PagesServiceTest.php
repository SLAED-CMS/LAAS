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

    public function testCreateLeavesContentUnchangedWhenNormalizationDisabled(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db);
        $content = 'Plain content';

        $page = $service->create([
            'title' => 'Plain',
            'slug' => 'plain',
            'content' => $content,
            'status' => 'draft',
        ]);

        $this->assertSame($content, $page['content'] ?? null);
    }

    public function testCreateNormalizesHtmlAndMarkdownWhenEnabled(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db, [
            'app' => [
                'pages_normalize_enabled' => true,
            ],
        ], new \Laas\Content\ContentNormalizer(
            new \Laas\Content\MarkdownRenderer(),
            new \Laas\Security\HtmlSanitizer()
        ));

        $htmlPage = $service->create([
            'title' => 'Html',
            'slug' => 'html',
            'content' => '<p>Hi</p><img src="x" onerror="alert(1)">',
            'content_format' => 'html',
            'status' => 'draft',
        ]);

        $htmlContent = (string) ($htmlPage['content'] ?? '');
        $this->assertStringContainsString('<img', $htmlContent);
        $this->assertStringNotContainsString('onerror', strtolower($htmlContent));

        $markdownPage = $service->create([
            'title' => 'Markdown',
            'slug' => 'markdown',
            'content' => '**bold** <script>alert(1)</script>',
            'content_format' => 'markdown',
            'status' => 'draft',
        ]);

        $markdownContent = (string) ($markdownPage['content'] ?? '');
        $this->assertStringContainsString('<strong>bold</strong>', $markdownContent);
        $this->assertStringNotContainsString('<script', strtolower($markdownContent));
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
