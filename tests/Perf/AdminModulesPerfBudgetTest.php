<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfBudgetTestCase.php';
require_once __DIR__ . '/PerfBudgetTestHelper.php';

use Laas\Core\Kernel;
use Laas\Http\Request;
use Laas\Http\RequestContext;
use Tests\Perf\PerfBudgetTestHelper;

final class AdminModulesPerfBudgetTest extends PerfBudgetTestCase
{
    public function testAdminModulesListBudget(): void
    {
        $dbPath = $this->prepareDatabase('admin-modules-perf', function (PDO $pdo): void {
            $this->seedRbac($pdo, ['admin.access', 'admin.modules.manage']);
            $this->seedModulesTable($pdo);
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);
        RequestContext::resetMetrics();

        $kernel = new Kernel(dirname(__DIR__, 2));
        $response = $kernel->handle($this->makeRequest('/admin/modules'));

        $this->assertSame(200, $response->getStatus());

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/admin/modules');
        $this->assertNotNull($budget, 'Budget not found for /admin/modules');

        PerfBudgetTestHelper::assertBudget($this, '/admin/modules', $snapshot, $budget);
        $this->assertLessThanOrEqual(4, $snapshot->sqlDup(), 'Duplicate SQL detected on /admin/modules');
    }

    public function testAdminModulesDetailsBudget(): void
    {
        $dbPath = $this->prepareDatabase('admin-modules-details-perf', function (PDO $pdo): void {
            $this->seedRbac($pdo, ['admin.access', 'admin.modules.manage']);
            $this->seedModulesTable($pdo);
        });
        $this->setDatabaseEnv($dbPath);
        $this->startSession(1);
        RequestContext::resetMetrics();

        $kernel = new Kernel(dirname(__DIR__, 2));
        $request = new Request('GET', '/admin/modules/details', ['module' => 'admin'], [], ['accept' => 'text/html'], '');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatus());

        $snapshot = PerfBudgetTestHelper::snapshot();
        $registry = PerfBudgetTestHelper::registry(dirname(__DIR__, 2));
        $budget = $registry->budgetForPath('/admin/modules/details');
        $this->assertNotNull($budget, 'Budget not found for /admin/modules/details');

        PerfBudgetTestHelper::assertBudget($this, '/admin/modules/details', $snapshot, $budget);
        $this->assertLessThanOrEqual(4, $snapshot->sqlDup(), 'Duplicate SQL detected on /admin/modules/details');
    }
}
