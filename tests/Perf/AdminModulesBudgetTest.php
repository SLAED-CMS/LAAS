<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';

use Laas\Core\Kernel;
use Laas\Http\RequestContext;
use Laas\Perf\PerfBudget;

final class AdminModulesBudgetTest extends PerfBudgetTestCase
{
    public function testAdminModulesBudget(): void
    {
        $dbPath = $this->prepareDatabase('admin-modules', function (PDO $pdo): void {
            $this->seedRbac($pdo, ['admin.access', 'admin.modules.manage']);
            $this->seedModulesTable($pdo);
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);
        RequestContext::resetMetrics();

        $kernel = new Kernel(dirname(__DIR__, 2));
        $response = $kernel->handle($this->makeRequest('/admin/modules'));

        $this->assertSame(200, $response->getStatus());

        $metrics = RequestContext::metrics();
        $this->assertGreaterThan(0, $metrics['sql_unique'] ?? 0);

        $budget = new PerfBudget([
            'max_total_ms' => 3000,
            'max_sql_unique' => 50,
            'max_sql_dup' => 15,
            'max_sql_ms' => 400,
        ]);
        $result = $budget->check(new RequestContext(), $metrics);

        $this->assertFalse($result->hasViolations(), $this->formatViolations($result));
    }
}
