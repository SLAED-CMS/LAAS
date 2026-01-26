<?php
declare(strict_types=1);

use Laas\Database\DatabaseManager;
use Laas\Domain\Security\SecurityReportsService;
use Laas\Content\ContentNormalizer;
use Laas\Content\MarkdownRenderer;
use Laas\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class SecurityReportsServiceTest extends TestCase
{
    public function testListReturnsArray(): void
    {
        $db = $this->createDb();
        $service = new SecurityReportsService($db);
        $this->seedReport($db, 1, 'csp', 'new', '2026-01-01 00:00:00', 'script-src');
        $this->seedReport($db, 2, 'other', 'triaged', '2026-01-02 00:00:00', 'style-src');

        $items = $service->list();

        $this->assertCount(2, $items);
    }

    public function testFilterBySince(): void
    {
        $db = $this->createDb();
        $service = new SecurityReportsService($db);
        $this->seedReport($db, 1, 'csp', 'new', '2026-01-01 00:00:00', 'script-src');
        $this->seedReport($db, 2, 'csp', 'new', '2026-01-03 00:00:00', 'script-src');

        $items = $service->list(['since' => '2026-01-02 00:00:00']);

        $this->assertCount(1, $items);
        $this->assertSame(2, (int) ($items[0]['id'] ?? 0));
    }

    public function testCountRespectsFilters(): void
    {
        $db = $this->createDb();
        $service = new SecurityReportsService($db);
        $this->seedReport($db, 1, 'csp', 'new', '2026-01-01 00:00:00', 'script-src');
        $this->seedReport($db, 2, 'csp', 'triaged', '2026-01-02 00:00:00', 'script-src');
        $this->seedReport($db, 3, 'other', 'ignored', '2026-01-03 00:00:00', 'style-src');

        $count = $service->count(['status' => 'triaged']);

        $this->assertSame(1, $count);
    }

    public function testInsertKeepsRawContentWhenNormalizationDisabled(): void
    {
        $db = $this->createDb();
        $service = new SecurityReportsService($db, ['security' => ['reports_normalize_enabled' => false]]);
        $payload = [
            'document_uri' => '<p>Doc</p><script>alert(1)</script>',
            'violated_directive' => '<img src=x onerror=alert(1)>blocked',
            'blocked_uri' => '<a href="javascript:alert(1)">bad</a>',
            'user_agent' => '<strong>Agent</strong>',
            'ip' => '203.0.113.10',
        ];

        $service->insert($payload);
        $row = $this->fetchLatestReport($db);

        $this->assertSame($payload['document_uri'], $row['document_uri']);
        $this->assertSame($payload['violated_directive'], $row['violated_directive']);
        $this->assertSame($payload['blocked_uri'], $row['blocked_uri']);
        $this->assertSame($payload['user_agent'], $row['user_agent']);
    }

    public function testInsertSanitizesUserPlainWhenEnabled(): void
    {
        $db = $this->createDb();
        $normalizer = new ContentNormalizer(new MarkdownRenderer(), new HtmlSanitizer());
        $service = new SecurityReportsService($db, ['security' => ['reports_normalize_enabled' => true]], $normalizer);
        $payload = [
            'document_uri' => '<p>Doc</p><script>alert(1)</script>',
            'violated_directive' => '<img src=x onerror=alert(1)>blocked',
            'blocked_uri' => '<a href="javascript:alert(1)">bad</a><a href="https://example.com">ok</a>',
            'user_agent' => '<strong>Agent</strong><span>nope</span>',
            'ip' => '203.0.113.11',
            'content_format' => 'html',
        ];

        $service->insert($payload);
        $row = $this->fetchLatestReport($db);

        $this->assertStringNotContainsString('<script>', $row['document_uri']);
        $this->assertStringNotContainsString('<img', $row['violated_directive']);
        $this->assertStringNotContainsString('onerror', $row['violated_directive']);
        $this->assertStringNotContainsString('javascript:', $row['blocked_uri']);
        $this->assertStringContainsString('https://example.com', $row['blocked_uri']);
        $this->assertStringContainsString('<strong>Agent</strong>', $row['user_agent']);
        $this->assertStringNotContainsString('<span>', $row['user_agent']);
    }

    public function testInsertNormalizesMarkdownWhenEnabled(): void
    {
        $db = $this->createDb();
        $normalizer = new ContentNormalizer(new MarkdownRenderer(), new HtmlSanitizer());
        $service = new SecurityReportsService($db, ['security' => ['reports_normalize_enabled' => true]], $normalizer);
        $payload = [
            'document_uri' => '**bold** <script>alert(1)</script>',
            'violated_directive' => 'style-src',
            'blocked_uri' => 'https://example.com/blocked',
            'user_agent' => 'Agent',
            'ip' => '203.0.113.12',
            'content_format' => 'markdown',
        ];

        $service->insert($payload);
        $row = $this->fetchLatestReport($db);

        $this->assertStringContainsString('<strong>bold</strong>', $row['document_uri']);
        $this->assertStringNotContainsString('<script>', $row['document_uri']);
    }

    private function createDb(): DatabaseManager
    {
        $db = new DatabaseManager(['driver' => 'sqlite', 'database' => ':memory:']);
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE security_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NULL,
                document_uri TEXT NOT NULL,
                violated_directive TEXT NOT NULL,
                blocked_uri TEXT NOT NULL,
                user_agent TEXT NOT NULL,
                ip TEXT NOT NULL,
                request_id TEXT NULL,
                triaged_at TEXT NULL,
                ignored_at TEXT NULL
            )'
        );

        return $db;
    }

    private function seedReport(DatabaseManager $db, int $id, string $type, string $status, string $createdAt, string $directive): void
    {
        $stmt = $db->pdo()->prepare(
            'INSERT INTO security_reports (id, type, status, created_at, updated_at, document_uri, violated_directive, blocked_uri, user_agent, ip, request_id)
             VALUES (:id, :type, :status, :created_at, :updated_at, :document_uri, :violated_directive, :blocked_uri, :user_agent, :ip, :request_id)'
        );
        $stmt->execute([
            'id' => $id,
            'type' => $type,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'document_uri' => 'https://example.com',
            'violated_directive' => $directive,
            'blocked_uri' => 'https://example.com/blocked',
            'user_agent' => 'TestAgent',
            'ip' => '203.0.113.10',
            'request_id' => 'req-' . $id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestReport(DatabaseManager $db): array
    {
        $stmt = $db->pdo()->query('SELECT * FROM security_reports ORDER BY id DESC LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch();

        $this->assertIsArray($row);

        return $row;
    }
}
