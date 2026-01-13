<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Perf\PerfBudgetEnforcer;
use Laas\Support\RequestScope;
use PHPUnit\Framework\TestCase;

final class PerfGuardBlockJsonTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestScope::reset();
        RequestScope::setRequest(null);
    }

    public function testBlockReturnsJsonEnvelopeAndRetryAfter(): void
    {
        $request = new Request('GET', '/api/test', [], [], ['accept' => 'application/json'], '');
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
        usleep(2000);

        $config = [
            'budgets_enabled' => false,
            'guard_enabled' => true,
            'guard_mode' => 'block',
            'guard_exempt_paths' => [],
            'guard_exempt_routes' => [],
            'db_max_queries' => 1,
            'db_max_unique' => 1,
            'db_max_total_ms' => 1,
            'http_max_calls' => 1,
            'http_max_total_ms' => 1,
            'total_max_ms' => 1,
        ];

        $enforcer = new PerfBudgetEnforcer($config);
        $enforcer->evaluate($context);

        $this->assertSame(503, $response->getStatus());
        $this->assertSame('5', $response->getHeader('Retry-After'));
        $payload = json_decode($response->getBody(), true);
        $this->assertSame(ErrorCode::PERF_BUDGET_EXCEEDED, $payload['error']['code'] ?? null);
    }
}
