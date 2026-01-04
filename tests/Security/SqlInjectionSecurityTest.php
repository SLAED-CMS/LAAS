<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/SecurityTestHelper.php';

use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Support\Search\LikeEscaper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Security\Support\SecurityTestHelper;

#[Group('security')]
final class SqlInjectionSecurityTest extends TestCase
{
    public function testLikeEscaperEscapesWildcards(): void
    {
        $escaped = LikeEscaper::escape('%_');
        $this->assertSame('\\%\\_', $escaped);
    }

    public function testInjectionDoesNotBypassSearch(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        $pdo->exec("INSERT INTO users (id, username, email, status, created_at, updated_at) VALUES (1, 'alice', 'a@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO users (id, username, email, status, created_at, updated_at) VALUES (2, 'bob', 'b@example.com', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");

        $repo = new UsersRepository($pdo);
        $results = $repo->search("' OR 1=1 --", 50, 0);
        $this->assertSame([], $results);
    }

    public function testLimitClampedToMax50(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedPagesTable($pdo);

        for ($i = 1; $i <= 60; $i++) {
            $pdo->exec("INSERT INTO pages (id, title, slug, status, content, created_at, updated_at) VALUES ($i, 'Title $i', 'slug-$i', 'published', 'content', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        }

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $repo = new PagesRepository($db);
        $rows = $repo->search('Title', 999, -10, 'published');

        $this->assertCount(50, $rows);
        $this->assertSame('Title 60', (string) ($rows[0]['title'] ?? ''));
    }

    public function testMediaSearchUsesEscapedWildcards(): void
    {
        $pdo = SecurityTestHelper::createSqlitePdo();
        SecurityTestHelper::seedRbacTables($pdo);
        SecurityTestHelper::seedMediaTable($pdo);
        $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, created_at) VALUES (1, 'u', 'uploads/a.png', '100_percent.png', 'image/png', 10, '2026-01-01 00:00:00')");

        $db = SecurityTestHelper::dbManagerFromPdo($pdo);
        $repo = new MediaRepository($db);
        $rows = $repo->search('%', 50, 0);

        $this->assertSame([], $rows);
    }
}
