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
     * @param array<string, mixed>|PerfSnapshot $metrics
     */
    public function check(RequestContext $ctx, array|PerfSnapshot $metrics): PerfBudgetResult
    {
        $result = new PerfBudgetResult();
        if ($metrics instanceof PerfSnapshot) {
            $resolved = $this->normalizeMetrics($metrics->toArray());
        } else {
            $resolved = $this->normalizeMetrics($metrics);
        }
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

        $this->checkMetric(
            $result,
            'total_ms',
            $resolved['total_ms'],
            $this->warnThreshold('total_ms'),
            $this->hardThreshold('total_ms', 'max_total_ms')
        );
        $this->checkMetric(
            $result,
            'memory_mb',
            $resolved['memory_mb'],
            $this->warnThreshold('memory_mb'),
            $this->hardThreshold('memory_mb')
        );
        $this->checkMetric(
            $result,
            'sql_count',
            $resolved['sql_count'],
            $this->warnThreshold('sql_count'),
            $this->hardThreshold('sql_count')
        );
        $this->checkMetric(
            $result,
            'sql_unique',
            $resolved['sql_unique'],
            $this->warnThreshold('sql_unique'),
            $this->hardThreshold('sql_unique', 'max_sql_unique')
        );
        $this->checkMetric(
            $result,
            'sql_dup',
            $resolved['sql_dup'],
            $this->warnThreshold('sql_dup'),
            $this->hardThreshold('sql_dup', 'max_sql_dup')
        );
        $this->checkMetric(
            $result,
            'sql_ms',
            $resolved['sql_ms'],
            $this->warnThreshold('sql_ms'),
            $this->hardThreshold('sql_ms', 'max_sql_ms')
        );

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
            'memory_mb' => $context->getMemoryPeakMb(),
            'sql_count' => $context->getDbCount(),
            'sql_unique' => $context->getDbUniqueCount(),
            'sql_dup' => $context->getDbDuplicateCount(),
            'sql_ms' => $context->getDbTotalMs(),
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{total_ms: float, memory_mb: float, sql_count: int, sql_unique: int, sql_dup: int, sql_ms: float}
     */
    private function normalizeMetrics(array $metrics): array
    {
        return [
            'total_ms' => (float) ($metrics['total_ms'] ?? 0),
            'memory_mb' => (float) ($metrics['memory_mb'] ?? 0),
            'sql_count' => (int) ($metrics['sql_count'] ?? 0),
            'sql_unique' => (int) ($metrics['sql_unique'] ?? 0),
            'sql_dup' => (int) ($metrics['sql_dup'] ?? 0),
            'sql_ms' => (float) ($metrics['sql_ms'] ?? 0),
        ];
    }

    private function warnThreshold(string $metric): float|int
    {
        $key = $metric . '_warn';
        $value = $this->budget[$key] ?? 0;
        return is_float($value) ? $value : (int) $value;
    }

    private function hardThreshold(string $metric, ?string $legacyMaxKey = null): float|int
    {
        $key = $metric . '_hard';
        $value = $this->budget[$key] ?? 0;
        if ($value <= 0 && $legacyMaxKey !== null) {
            $value = $this->budget[$legacyMaxKey] ?? 0;
        }
        return is_float($value) ? $value : (int) $value;
    }

    private function checkMetric(
        PerfBudgetResult $result,
        string $metric,
        float|int $value,
        float|int $warn,
        float|int $hard
    ): void {
        if ($hard > 0 && $value > $hard) {
            $result->addViolation($metric, $value, $hard, 'hard');
            return;
        }
        if ($warn > 0 && $value > $warn) {
            $result->addViolation($metric, $value, $warn, 'warn');
            return;
        }
    }
}
