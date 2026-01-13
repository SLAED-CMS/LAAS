<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Perf\PerfBudgetEnforcer;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class PerfGuardAdminOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::reset();
        RequestScope::setRequest(null);
    }

    public function testAdminOverrideApplies(): void
    {
        $request = new Request('GET', '/admin/users', [], [], ['accept' => 'application/json'], '');
        RequestScope::setRequest($request);
        $response = new Response('ok', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        RequestScope::set('response', $response);

        $context = new DevToolsContext([
            'enabled' => false,
            'debug' => false,
            'env' => 'prod',
            'is_dev' => false,
            'store_sql' => false,
        ]);

        $context->addDbQuery('SELECT 1', 0, 1.0);
        $context->addDbQuery('SELECT 2', 0, 1.0);
        $context->addDbQuery('SELECT 3', 0, 1.0);
        usleep(2000);

        $config = [
            'budgets_enabled' => false,
            'guard_enabled' => true,
            'guard_mode' => 'block',
            'guard_exempt_paths' => [],
            'guard_exempt_routes' => [],
            'db_max_queries' => 100,
            'db_max_unique' => 100,
            'db_max_total_ms' => 1000,
            'http_max_calls' => 100,
            'http_max_total_ms' => 1000,
            'total_max_ms' => 10000,
            'db_max_queries_admin' => 2,
            'total_max_ms_admin' => 10000,
        ];

        $enforcer = new PerfBudgetEnforcer($config);
        $enforcer->evaluate($context);

        $this->assertSame(503, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame(ErrorCode::PERF_BUDGET_EXCEEDED, $payload['error']['code'] ?? null);
    }
}
