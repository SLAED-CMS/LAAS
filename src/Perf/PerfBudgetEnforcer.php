<?php
declare(strict_types=1);

namespace Laas\Perf;

use Laas\DevTools\DevToolsContext;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;

final class PerfBudgetEnforcer
{
    public function __construct(private array $config)
    {
    }

    public function evaluate(DevToolsContext $context): PerfBudgetResult
    {
        $result = new PerfBudgetResult();
        $enabled = (bool) ($this->config['enabled'] ?? false);
        if (!$enabled) {
            return $result;
        }

        $context->finalize();

        $totalMs = $context->getDurationMs();
        $sqlCount = $context->getDbCount();
        $sqlTotalMs = $context->getDbTotalMs();

        $this->checkMetric($result, 'total_ms', $totalMs, (float) ($this->config['total_ms_warn'] ?? 0), (float) ($this->config['total_ms_hard'] ?? 0));
        $this->checkMetric($result, 'sql_count', $sqlCount, (int) ($this->config['sql_count_warn'] ?? 0), (int) ($this->config['sql_count_hard'] ?? 0));
        $this->checkMetric($result, 'sql_ms', $sqlTotalMs, (float) ($this->config['sql_ms_warn'] ?? 0), (float) ($this->config['sql_ms_hard'] ?? 0));

        if ($result->hasViolations()) {
            $message = 'Performance budget exceeded';
            $context->addWarning('perf_budget', $message);
        }

        return $result;
    }

    public function buildOverBudgetResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return ErrorResponse::respond($request, 'system.over_budget', [], 503, [], 'perf.budget');
        }

        return new Response('system.over_budget', 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function checkMetric(PerfBudgetResult $result, string $metric, float|int $value, float|int $warn, float|int $hard): void
    {
        if ($hard > 0 && $value >= $hard) {
            $result->addViolation($metric, $value, $hard, 'hard');
            return;
        }
        if ($warn > 0 && $value >= $warn) {
            $result->addViolation($metric, $value, $warn, 'warn');
        }
    }
}
