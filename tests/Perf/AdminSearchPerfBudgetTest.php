<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';
require_once __DIR__ . '/PerfBudgetTestHelper.php';

use Laas\Core\Kernel;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Tests\Perf\PerfBudgetTestHelper;

final class AdminSearchPerfBudgetTest extends PerfBudgetTestCase
{
    public function testAdminSearchBudget(): void
    {
        $dbPath = $this->prepareDatabase('admin-search-perf', function (PDO $pdo): void {
            $this->seedRbac($pdo, [
                'admin.access',
                'admin.modules.manage',
                'admin.settings.manage',
                'pages.view',
                'media.view',
                'users.view',
                'menus.edit',
            ]);
            $this->seedModulesTable($pdo);
            $this->seedPagesTable($pdo);
            $this->seedMenusTables($pdo);
            $this->seedMediaTable($pdo);

            $pdo->exec("INSERT INTO pages (title, slug, status, content, created_at, updated_at) VALUES ('Sample', 'sample', 'published', 'Body', '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at) VALUES (1, 'Home', '/', 1, 1, 0, '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, created_at, status) VALUES (1, 'u1', 'path', 'photo.jpg', 'image/jpeg', 123, '2026-01-01', 'ready')");
            $pdo->exec("INSERT INTO users (id, username, password_hash, status, created_at, updated_at) VALUES (2, 'alice', 'hash', 1, '2026-01-01', '2026-01-01')");
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);

        $kernel = new Kernel(dirname(__DIR__, 2));
        $request = new Request('GET', '/admin/search', ['q' => 'sample'], [], ['accept' => 'text/html'], '');

        RequestContext::resetMetrics();
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatus());

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/admin/search');
        $this->assertNotNull($budget, 'Budget not found for /admin/search');

        PerfBudgetTestHelper::assertBudget($this, '/admin/search', $snapshot, $budget);
        $this->assertLessThanOrEqual(4, $snapshot->sqlDup(), 'Duplicate SQL detected on /admin/search');
    }

    public function testAdminSearchPaletteBudget(): void
    {
        $featuresPath = dirname(__DIR__, 2) . '/config/admin_features.php';
        $features = is_file($featuresPath) ? require $featuresPath : [];
        if (!is_array($features) || !($features['devtools_palette'] ?? false)) {
            $this->markTestSkipped('Admin search palette disabled.');
        }

        $dbPath = $this->prepareDatabase('admin-search-palette-perf', function (PDO $pdo): void {
            $this->seedRbac($pdo, [
                'admin.access',
                'admin.modules.manage',
                'admin.settings.manage',
                'pages.view',
                'media.view',
                'users.view',
                'menus.edit',
            ]);
            $this->seedModulesTable($pdo);
            $this->seedPagesTable($pdo);
            $this->seedMenusTables($pdo);
            $this->seedMediaTable($pdo);

            $pdo->exec("INSERT INTO pages (title, slug, status, content, created_at, updated_at) VALUES ('Sample', 'sample', 'published', 'Body', '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at) VALUES (1, 'Home', '/', 1, 1, 0, '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO media_files (id, uuid, disk_path, original_name, mime_type, size_bytes, created_at, status) VALUES (1, 'u1', 'path', 'photo.jpg', 'image/jpeg', 123, '2026-01-01', 'ready')");
            $pdo->exec("INSERT INTO users (id, username, password_hash, status, created_at, updated_at) VALUES (2, 'alice', 'hash', 1, '2026-01-01', '2026-01-01')");
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);

        $kernel = new Kernel(dirname(__DIR__, 2));
        $request = new Request('GET', '/admin/search/palette', ['q' => 'sample'], [], ['accept' => 'application/json'], '');

        RequestContext::resetMetrics();
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatus());

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/admin/search/palette');
        $this->assertNotNull($budget, 'Budget not found for /admin/search/palette');

        PerfBudgetTestHelper::assertBudget($this, '/admin/search/palette', $snapshot, $budget);
        $this->assertLessThanOrEqual(4, $snapshot->sqlDup(), 'Duplicate SQL detected on /admin/search/palette');
    }
}
