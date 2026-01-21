<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';
require_once __DIR__ . '/PerfBudgetTestHelper.php';

use Laas\Core\Kernel;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Tests\Perf\PerfBudgetTestHelper;

final class HeadlessV2PerfBudgetTest extends PerfBudgetTestCase
{
    public function testHeadlessPagesBudgetAndEtag(): void
    {
        $dbPath = $this->prepareDatabase('api-pages-perf', function (PDO $pdo): void {
            $this->seedModulesTable($pdo);
            $this->seedPagesTable($pdo);
            $this->seedPagesRevisions($pdo);
        });
        $this->setDatabaseEnv($dbPath);

        $kernel = new Kernel(dirname(__DIR__, 2));

        RequestContext::resetMetrics();
        $response = $kernel->handle($this->makeRequest('/api/v2/pages', [
            'accept' => 'application/json',
        ]));
        $this->assertSame(200, $response->getStatus());
        $etag = $response->getHeader('ETag');
        $this->assertNotNull($etag, 'ETag missing for /api/v2/pages');

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/api/v2/pages');
        $this->assertNotNull($budget, 'Budget not found for /api/v2/pages');
        PerfBudgetTestHelper::assertBudget($this, '/api/v2/pages', $snapshot, $budget);
        $this->assertLessThanOrEqual(3, $snapshot->sqlDup(), 'Duplicate SQL detected on /api/v2/pages');

        RequestContext::resetMetrics();
        $etagRequest = new Request('GET', '/api/v2/pages', [], [], [
            'accept' => 'application/json',
            'if-none-match' => $etag,
        ], '');
        $etagResponse = $kernel->handle($etagRequest);
        $this->assertSame(304, $etagResponse->getStatus());

        $etagSnapshot = PerfBudgetTestHelper::snapshot();
        $etagBudget = $registry->budgetForPath('/api/v2/pages:304');
        $this->assertNotNull($etagBudget, 'Budget not found for /api/v2/pages:304');
        PerfBudgetTestHelper::assertBudget($this, '/api/v2/pages:304', $etagSnapshot, $etagBudget);
        $this->assertLessThanOrEqual(3, $etagSnapshot->sqlDup(), 'Duplicate SQL detected on /api/v2/pages (304)');
        $this->assertLessThanOrEqual(
            $snapshot->sqlCount(),
            $etagSnapshot->sqlCount(),
            'ETag path must not increase SQL count for /api/v2/pages'
        );
    }

    public function testHeadlessMenusBudgetAndEtag(): void
    {
        $dbPath = $this->prepareDatabase('api-menus-perf', function (PDO $pdo): void {
            $this->seedModulesTable($pdo);
            $this->seedMenusTables($pdo);
            $pdo->exec("INSERT INTO menus (id, name, title, created_at, updated_at) VALUES (1, 'main', 'Main', '2026-01-01', '2026-01-01')");
            $pdo->exec("INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at) VALUES (1, 'Home', '/', 1, 1, 0, '2026-01-01', '2026-01-01')");
        });
        $this->setDatabaseEnv($dbPath);

        $kernel = new Kernel(dirname(__DIR__, 2));

        RequestContext::resetMetrics();
        $response = $kernel->handle($this->makeRequest('/api/v2/menus', [
            'accept' => 'application/json',
        ]));
        $this->assertSame(200, $response->getStatus());
        $etag = $response->getHeader('ETag');
        $this->assertNotNull($etag, 'ETag missing for /api/v2/menus');

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/api/v2/menus');
        $this->assertNotNull($budget, 'Budget not found for /api/v2/menus');
        PerfBudgetTestHelper::assertBudget($this, '/api/v2/menus', $snapshot, $budget);
        $this->assertLessThanOrEqual(3, $snapshot->sqlDup(), 'Duplicate SQL detected on /api/v2/menus');

        RequestContext::resetMetrics();
        $etagRequest = new Request('GET', '/api/v2/menus', [], [], [
            'accept' => 'application/json',
            'if-none-match' => $etag,
        ], '');
        $etagResponse = $kernel->handle($etagRequest);
        $this->assertSame(304, $etagResponse->getStatus());

        $etagSnapshot = PerfBudgetTestHelper::snapshot();
        $etagBudget = $registry->budgetForPath('/api/v2/menus:304');
        $this->assertNotNull($etagBudget, 'Budget not found for /api/v2/menus:304');
        PerfBudgetTestHelper::assertBudget($this, '/api/v2/menus:304', $etagSnapshot, $etagBudget);
        $this->assertLessThanOrEqual(3, $etagSnapshot->sqlDup(), 'Duplicate SQL detected on /api/v2/menus (304)');
        $this->assertLessThanOrEqual(
            $snapshot->sqlCount(),
            $etagSnapshot->sqlCount(),
            'ETag path must not increase SQL count for /api/v2/menus'
        );
    }
}
