<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use Laas\Http\Request;
use Laas\Perf\PerfBudgetEnforcer;
use PHPUnit\Framework\TestCase;

final class PerfBudgetHardFailTest extends TestCase
{
    public function testHardFailReturnsJson503(): void
    {
        $config = [
            'enabled' => true,
            'hard_fail' => true,
            'total_ms_warn' => 1,
            'total_ms_hard' => 1,
            'sql_count_warn' => 1,
            'sql_count_hard' => 1,
            'sql_ms_warn' => 1,
            'sql_ms_hard' => 1,
        ];

        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'is_dev' => true,
            'store_sql' => false,
        ]);

        usleep(2000);
        $context->addDbQuery('SELECT 1', 0, 2.0);

        $enforcer = new PerfBudgetEnforcer($config);
        $result = $enforcer->evaluate($context);
        $this->assertTrue($result->isHard());

        $request = new Request('GET', '/', [], [], ['accept' => 'application/json'], '');
        $response = $enforcer->buildOverBudgetResponse($request);

        $this->assertSame(503, $response->getStatus());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame('system.over_budget', $payload['error'] ?? null);
    }

    public function testHardFailReturnsPlainTextForHtml(): void
    {
        $config = [
            'enabled' => true,
            'hard_fail' => true,
            'total_ms_warn' => 1,
            'total_ms_hard' => 1,
            'sql_count_warn' => 1,
            'sql_count_hard' => 1,
            'sql_ms_warn' => 1,
            'sql_ms_hard' => 1,
        ];

        $context = new DevToolsContext([
            'enabled' => true,
            'debug' => true,
            'env' => 'dev',
            'is_dev' => true,
            'store_sql' => false,
        ]);

        usleep(2000);
        $context->addDbQuery('SELECT 1', 0, 2.0);

        $enforcer = new PerfBudgetEnforcer($config);
        $result = $enforcer->evaluate($context);
        $this->assertTrue($result->isHard());

        $request = new Request('GET', '/', [], [], ['accept' => 'text/html'], '');
        $response = $enforcer->buildOverBudgetResponse($request);

        $this->assertSame(503, $response->getStatus());
        $this->assertSame('system.over_budget', $response->getBody());
    }
}

