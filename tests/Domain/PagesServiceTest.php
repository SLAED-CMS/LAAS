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
            'content' => '<p>Hi</p><a href="https://example.com">ok</a><a href="javascript:alert(1)">x</a><img src="x" onerror="alert(1)">',
            'content_format' => 'html',
            'status' => 'draft',
        ]);

        $htmlContent = (string) ($htmlPage['content'] ?? '');
        $lowerHtml = strtolower($htmlContent);
        $this->assertStringContainsString('<p>Hi</p>', $htmlContent);
        $this->assertStringContainsString('href="https://example.com"', $htmlContent);
        $this->assertStringContainsString('<img', $htmlContent);
        $this->assertStringNotContainsString('onerror', $lowerHtml);
        $this->assertStringNotContainsString('javascript:', $lowerHtml);
        $this->assertStringNotContainsString('href="javascript', $lowerHtml);

        $markdownPage = $service->create([
            'title' => 'Markdown',
            'slug' => 'markdown',
            'content' => '**bold** <script>alert(1)</script>',
            'content_format' => 'markdown',
            'status' => 'draft',
        ]);

        $markdownContent = (string) ($markdownPage['content'] ?? '');
        $lowerMarkdown = strtolower($markdownContent);
        $this->assertStringContainsString('<strong>bold</strong>', $markdownContent);
        $this->assertStringNotContainsString('<script', $lowerMarkdown);
    }

    public function testBlocksContentUnchangedWhenNormalizationDisabled(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db);

        $page = $service->create([
            'title' => 'Blocks',
            'slug' => 'blocks',
            'content' => 'Text',
            'status' => 'draft',
        ]);
        $pageId = (int) ($page['id'] ?? 0);

        $blocks = [
            [
                'type' => 'rich_text',
                'data' => [
                    'html' => '<p>Hello</p><img src="https://example.com/x.png" onerror="alert(1)"><script>alert(1)</script>',
                ],
            ],
        ];

        $service->createRevision($pageId, $blocks, null);

        $storedBlocks = $service->findLatestBlocks($pageId);
        $this->assertSame($blocks[0]['data']['html'], $storedBlocks[0]['data']['html'] ?? null);
    }

    public function testBlocksContentNormalizedWhenEnabledHtml(): void
    {
        $db = $this->createDb();
        $service = new PagesService($db, [
            'app' => [
                'blocks_normalize_enabled' => true,
            ],
        ], new \Laas\Content\ContentNormalizer(
            new \Laas\Content\MarkdownRenderer(),
            new \Laas\Security\HtmlSanitizer()
        ));

        $page = $service->create([
            'title' => 'Blocks Normalized',
            'slug' => 'blocks-normalized',
            'content' => 'Text',
            'status' => 'draft',
        ]);
        $pageId = (int) ($page['id'] ?? 0);

        $blocks = [
            [
                'type' => 'rich_text',
                'data' => [
                    'html' => '<p>Hi</p><a href="https://example.com">ok</a><a href="javascript:alert(1)">bad</a><img src="https://example.com/x.png" onerror="alert(1)"><script>alert(1)</script>',
                ],
            ],
        ];

        $service->createRevision($pageId, $blocks, null);

        $storedBlocks = $service->findLatestBlocks($pageId);
        $storedHtml = (string) ($storedBlocks[0]['data']['html'] ?? '');
        $lowerHtml = strtolower($storedHtml);
        $this->assertStringContainsString('<p>Hi</p>', $storedHtml);
        $this->assertStringContainsString('href="https://example.com"', $storedHtml);
        $this->assertStringNotContainsString('<script', $lowerHtml);
        $this->assertStringNotContainsString('onerror', $lowerHtml);
        $this->assertStringNotContainsString('javascript:', $lowerHtml);
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
        $pdo->exec(
            'CREATE TABLE pages_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER,
                blocks_json TEXT,
                created_at TEXT,
                created_by INTEGER
            )'
        );

        return $db;
    }
}
