<?php
declare(strict_types=1);

use Laas\DevTools\DevToolsContext;
use Laas\Perf\PerfBudgetEnforcer;
use PHPUnit\Framework\TestCase;

final class PerfBudgetSoftWarnTest extends TestCase
{
    public function testWarnAddsWarningTokenWithoutHardFail(): void
    {
        $config = [
            'enabled' => true,
            'hard_fail' => false,
            'total_ms_warn' => 1,
            'total_ms_hard' => 1000,
            'sql_count_warn' => 1,
            'sql_count_hard' => 1000,
            'sql_ms_warn' => 1,
            'sql_ms_hard' => 1000,
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

        $this->assertTrue($result->hasViolations());
        $this->assertFalse($result->isHard());

        $warnings = $context->getWarnings();
        $codes = array_map(static fn(array $row): string => (string) ($row['code'] ?? ''), $warnings);
        $this->assertContains('perf_budget', $codes);
    }
}

