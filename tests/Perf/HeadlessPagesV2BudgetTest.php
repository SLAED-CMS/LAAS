<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';

use Laas\Core\Kernel;
use Laas\Http\RequestContext;
use Laas\Perf\PerfBudget;

final class HeadlessPagesV2BudgetTest extends PerfBudgetTestCase
{
    public function testHeadlessPagesV2Budget(): void
    {
        $dbPath = $this->prepareDatabase('api-pages', function (PDO $pdo): void {
            $this->seedModulesTable($pdo);
            $this->seedPagesTable($pdo);
            $this->seedPagesRevisions($pdo);
        });
        $this->setDatabaseEnv($dbPath);
        RequestContext::resetMetrics();

        $kernel = new Kernel(dirname(__DIR__, 2));
        $response = $kernel->handle($this->makeRequest('/api/v2/pages', [
            'accept' => 'application/json',
        ]));

        $this->assertSame(200, $response->getStatus());

        $metrics = RequestContext::metrics();
        $this->assertGreaterThan(0, $metrics['sql_unique'] ?? 0);

        $budget = new PerfBudget([
            'max_total_ms' => 2500,
            'max_sql_unique' => 30,
            'max_sql_dup' => 10,
            'max_sql_ms' => 300,
        ]);
        $result = $budget->check(new RequestContext(), $metrics);

        $this->assertFalse($result->hasViolations(), $this->formatViolations($result));
    }
}
