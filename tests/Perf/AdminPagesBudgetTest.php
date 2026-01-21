<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';

use Laas\Core\Kernel;
use Laas\Http\RequestContext;
use Laas\Perf\PerfBudget;

final class AdminPagesBudgetTest extends PerfBudgetTestCase
{
    public function testAdminPagesBudget(): void
    {
        $dbPath = $this->prepareDatabase('admin-pages', function (PDO $pdo): void {
            $this->seedRbac($pdo, ['admin.access', 'pages.edit']);
            $this->seedModulesTable($pdo);
            $this->seedPagesTable($pdo);
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);
        RequestContext::resetMetrics();

        $kernel = new Kernel(dirname(__DIR__, 2));
        $response = $kernel->handle($this->makeRequest('/admin/pages'));

        $this->assertSame(200, $response->getStatus());

        $metrics = RequestContext::metrics();
        $this->assertGreaterThan(0, $metrics['sql_unique'] ?? 0);

        $budget = new PerfBudget([
            'max_total_ms' => 3000,
            'max_sql_unique' => 60,
            'max_sql_dup' => 15,
            'max_sql_ms' => 500,
        ]);
        $result = $budget->check(new RequestContext(), $metrics);

        $this->assertFalse($result->hasViolations(), $this->formatViolations($result));
    }
}
