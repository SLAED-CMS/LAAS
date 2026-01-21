<?php
declare(strict_types=1);

namespace Laas\Perf;

use Laas\DevTools\DevToolsContext;
use Laas\Http\RequestContext;
use Laas\Support\RequestScope;

final class PerfBudget
{
    /**
     * @param array<string, float|int> $budget
     */
    public function __construct(private array $budget)
    {
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function check(RequestContext $ctx, array $metrics): PerfBudgetResult
    {
        $result = new PerfBudgetResult();
        $resolved = $this->normalizeMetrics($metrics);
        if ($metrics === []) {
            $fromScope = $this->metricsFromScope();
            if ($fromScope !== null) {
                $resolved = $fromScope;
            } else {
                $fromContext = $ctx::metrics();
                if ($fromContext !== []) {
                    $resolved = $this->normalizeMetrics($fromContext);
                }
            }
        }

        $this->checkMetric($result, 'total_ms', $resolved['total_ms'], (float) ($this->budget['max_total_ms'] ?? 0));
        $this->checkMetric($result, 'sql_unique', $resolved['sql_unique'], (int) ($this->budget['max_sql_unique'] ?? 0));
        $this->checkMetric($result, 'sql_dup', $resolved['sql_dup'], (int) ($this->budget['max_sql_dup'] ?? 0));
        $this->checkMetric($result, 'sql_ms', $resolved['sql_ms'], (float) ($this->budget['max_sql_ms'] ?? 0));

        return $result;
    }

    private function metricsFromScope(): ?array
    {
        $context = RequestScope::get('devtools.context');
        if (!$context instanceof DevToolsContext) {
            return null;
        }

        $context->finalize();

        return [
            'total_ms' => $context->getDurationMs(),
            'sql_unique' => $context->getDbUniqueCount(),
            'sql_dup' => $context->getDbDuplicateCount(),
            'sql_ms' => $context->getDbTotalMs(),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{total_ms: float, sql_unique: int, sql_dup: int, sql_ms: float}
     */
    private function normalizeMetrics(array $metrics): array
    {
        return [
            'total_ms' => (float) ($metrics['total_ms'] ?? 0),
            'sql_unique' => (int) ($metrics['sql_unique'] ?? 0),
            'sql_dup' => (int) ($metrics['sql_dup'] ?? 0),
            'sql_ms' => (float) ($metrics['sql_ms'] ?? 0),
        ];
    }

    private function checkMetric(PerfBudgetResult $result, string $metric, float|int $value, float|int $max): void
    {
        if ($max <= 0) {
            return;
        }
        if ($value > $max) {
            $result->addViolation($metric, $value, $max, 'hard');
        }
    }
}
