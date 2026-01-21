<?php
declare(strict_types=1);

namespace Tests\Perf;

use Laas\Perf\PerfBudget;
use Laas\Perf\PerfBudgetRegistry;
use Laas\Perf\PerfBudgetResult;
use Laas\Perf\PerfSnapshot;
use PHPUnit\Framework\TestCase;

final class PerfBudgetTestHelper
{
    public static function snapshot(): PerfSnapshot
    {
        return PerfSnapshot::fromRequest();
    }

    public static function registry(string $rootPath): PerfBudgetRegistry
    {
        return PerfBudgetRegistry::fromConfig($rootPath);
    }

    public static function assertBudget(
        TestCase $test,
        string $label,
        PerfSnapshot $snapshot,
        PerfBudget $budget,
        bool $failOnWarn = false
    ): void {
        $result = $budget->check(new \Laas\Http\RequestContext(), $snapshot);
        if (!$result->hasViolations()) {
            return;
        }
        if (!$failOnWarn && !$result->isHard()) {
            return;
        }

        $test->fail(self::formatFailure($label, $snapshot, $result));
    }

    public static function formatFailure(string $label, PerfSnapshot $snapshot, PerfBudgetResult $result): string
    {
        $metrics = $snapshot->toArray();
        $summary = sprintf(
            'total_ms=%.1f memory_mb=%.1f sql_count=%d sql_unique=%d sql_dup=%d sql_ms=%.1f',
            $metrics['total_ms'],
            $metrics['memory_mb'],
            $metrics['sql_count'],
            $metrics['sql_unique'],
            $metrics['sql_dup'],
            $metrics['sql_ms']
        );
        $violations = $result->getViolations();
        $details = json_encode($violations, JSON_UNESCAPED_SLASHES);
        $details = is_string($details) ? $details : 'perf_budget_violations';

        return $label . ' | ' . $summary . ' | ' . $details;
    }
}
