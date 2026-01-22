<?php

declare(strict_types=1);

namespace Laas\Perf;

use Laas\DevTools\DevToolsContext;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Audit;
use Laas\Support\RequestScope;

final class PerfBudgetEnforcer
{
    public function __construct(private array $config)
    {
    }

    public function evaluate(DevToolsContext $context): PerfBudgetResult
    {
        $result = new PerfBudgetResult();
        $budgetsEnabled = (bool) ($this->config['budgets_enabled'] ?? ($this->config['enabled'] ?? false));
        $guardsEnabled = (bool) ($this->config['guard_enabled'] ?? false);
        if (!$budgetsEnabled && !$guardsEnabled) {
            return $result;
        }

        $context->finalize();

        $totalMs = $context->getDurationMs();
        $sqlCount = $context->getDbCount();
        $sqlUnique = $context->getDbUniqueCount();
        $sqlTotalMs = $context->getDbTotalMs();

        if ($budgetsEnabled) {
            $this->checkMetric($result, 'total_ms', $totalMs, (float) ($this->config['total_ms_warn'] ?? 0), (float) ($this->config['total_ms_hard'] ?? 0));
            $this->checkMetric($result, 'sql_count', $sqlCount, (int) ($this->config['sql_count_warn'] ?? 0), (int) ($this->config['sql_count_hard'] ?? 0));
            $this->checkMetric($result, 'sql_ms', $sqlTotalMs, (float) ($this->config['sql_ms_warn'] ?? 0), (float) ($this->config['sql_ms_hard'] ?? 0));
        }

        if ($result->hasViolations()) {
            $message = 'Performance budget exceeded';
            $context->addWarning('perf_budget', $message);
        }

        if ($guardsEnabled) {
            $this->evaluateGuard($context, $totalMs, $sqlCount, $sqlUnique, $sqlTotalMs);
        }

        return $result;
    }

    public function buildOverBudgetResponse(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'system.over_budget', [], 503, [], 'perf.budget');
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

    private function evaluateGuard(DevToolsContext $context, float $totalMs, int $sqlCount, int $sqlUnique, float $sqlTotalMs): void
    {
        $request = RequestScope::getRequest();
        if ($request === null) {
            return;
        }
        if ($this->isGuardExempt($request)) {
            return;
        }

        $limits = $this->guardLimits($request);
        $violations = [];

        $this->checkGuardMetric($violations, 'db.count', $sqlCount, (int) ($limits['db_max_queries'] ?? 0));
        $this->checkGuardMetric($violations, 'db.unique', $sqlUnique, (int) ($limits['db_max_unique'] ?? 0));
        $this->checkGuardMetric($violations, 'db.total_ms', $sqlTotalMs, (int) ($limits['db_max_total_ms'] ?? 0));
        $this->checkGuardMetric($violations, 'total_ms', $totalMs, (int) ($limits['total_max_ms'] ?? 0));

        $external = $context->getExternalStats();
        if (!empty($external['available'])) {
            $this->checkGuardMetric($violations, 'http.count', (int) ($external['count'] ?? 0), (int) ($limits['http_max_calls'] ?? 0));
            $this->checkGuardMetric($violations, 'http.total_ms', (float) ($external['total_ms'] ?? 0), (int) ($limits['http_max_total_ms'] ?? 0));
        }

        if ($violations === []) {
            return;
        }

        $mode = $this->guardMode();
        if ($mode === 'warn') {
            $message = $this->translateMessage($request, 'perf.guard_warn');
            $context->addWarning('perf_guard', $message);
            Audit::log('perf.guard.warn', 'perf_guard', null, $this->buildGuardMeta($request, $violations));
            return;
        }

        $response = RequestScope::get('response');
        if (!$response instanceof Response) {
            return;
        }
        if ($response->getStatus() >= 500) {
            return;
        }

        $blockResponse = $this->buildGuardBlockResponse($request, $context, $violations);
        $response->replace($blockResponse);
        Audit::log('perf.guard.blocked', 'perf_guard', null, $this->buildGuardMeta($request, $violations));
    }

    private function guardMode(): string
    {
        $mode = strtolower((string) ($this->config['guard_mode'] ?? 'warn'));
        return in_array($mode, ['warn', 'block'], true) ? $mode : 'warn';
    }

    private function guardLimits(Request $request): array
    {
        $limits = [
            'db_max_queries' => (int) ($this->config['db_max_queries'] ?? 0),
            'db_max_unique' => (int) ($this->config['db_max_unique'] ?? 0),
            'db_max_total_ms' => (int) ($this->config['db_max_total_ms'] ?? 0),
            'http_max_calls' => (int) ($this->config['http_max_calls'] ?? 0),
            'http_max_total_ms' => (int) ($this->config['http_max_total_ms'] ?? 0),
            'total_max_ms' => (int) ($this->config['total_max_ms'] ?? 0),
        ];

        if ($this->isAdminListRequest($request)) {
            $adminDb = (int) ($this->config['db_max_queries_admin'] ?? 0);
            if ($adminDb > 0) {
                $limits['db_max_queries'] = $adminDb;
            }
            $adminTotal = (int) ($this->config['total_max_ms_admin'] ?? 0);
            if ($adminTotal > 0) {
                $limits['total_max_ms'] = $adminTotal;
            }
        }

        return $limits;
    }

    private function isAdminListRequest(Request $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        return str_starts_with($request->getPath(), '/admin');
    }

    private function isGuardExempt(Request $request): bool
    {
        $path = $request->getPath();
        foreach ((array) ($this->config['guard_exempt_paths'] ?? []) as $pattern) {
            if ($this->matchesPattern($path, (string) $pattern)) {
                return true;
            }
        }

        $route = $request->getAttribute('route.pattern');
        if (is_string($route) && $route !== '') {
            foreach ((array) ($this->config['guard_exempt_routes'] ?? []) as $pattern) {
                if ($this->matchesPattern($route, (string) $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesPattern(string $value, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }
        if (!str_contains($pattern, '*')) {
            return $value === $pattern;
        }

        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $value);
    }

    private function checkGuardMetric(array &$violations, string $metric, float|int $value, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }
        if ($value < $limit) {
            return;
        }
        $violations[] = [
            'metric' => $metric,
            'value' => $value,
            'limit' => $limit,
        ];
    }

    private function buildGuardBlockResponse(Request $request, DevToolsContext $context, array $violations): Response
    {
        $details = $this->isDebug($context) ? ['exceeded' => $violations] : [];
        return ErrorResponse::respondForRequest(
            $request,
            ErrorCode::PERF_BUDGET_EXCEEDED,
            $details,
            503,
            [],
            'perf.guard',
            ['Retry-After' => '5']
        );
    }

    /** @param array<int, array{metric: string, value: float|int, limit: int}> $violations */
    private function buildGuardMeta(Request $request, array $violations): array
    {
        return [
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'violations' => array_map(static function (array $row): array {
                return [
                    'metric' => (string) ($row['metric'] ?? ''),
                    'value' => $row['value'] ?? null,
                    'limit' => $row['limit'] ?? null,
                ];
            }, $violations),
        ];
    }

    private function translateMessage(Request $request, string $key): string
    {
        $configPath = dirname(__DIR__, 2) . '/config/app.php';
        $appConfig = is_file($configPath) ? require $configPath : [];
        $appConfig = is_array($appConfig) ? $appConfig : [];
        $default = (string) ($appConfig['default_locale'] ?? 'en');
        $theme = (string) ($appConfig['theme'] ?? 'default');
        $translator = new \Laas\I18n\Translator(dirname(__DIR__, 2), $theme, $default);
        $resolver = new \Laas\I18n\LocaleResolver($appConfig);
        $resolved = $resolver->resolve($request);
        $locale = (string) ($resolved['locale'] ?? $default);
        $locale = $locale !== '' ? $locale : $default;
        return $translator->trans($key, [], $locale);
    }

    private function isDebug(DevToolsContext $context): bool
    {
        return (bool) $context->getFlag('debug', false);
    }
}
