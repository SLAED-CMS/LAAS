<?php
declare(strict_types=1);

$env = $_ENV;
$envString = static function (string $key, string $default) use ($env): string {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return (string) $value;
};
$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        return $default;
    }
    return (int) $value;
};
$envList = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || trim((string) $value) === '') {
        return $default;
    }
    $parts = array_map('trim', explode(',', (string) $value));
    $parts = array_values(array_filter($parts, static fn(string $item): bool => $item !== ''));
    return $parts === [] ? $default : $parts;
};

$budgetsEnabled = $envBool('PERF_BUDGET_ENABLED', true);
$guardsEnabled = $envBool('PERF_GUARDS_ENABLED', true);
$guardMode = strtolower($envString('PERF_GUARD_MODE', 'warn'));
if (!in_array($guardMode, ['warn', 'block'], true)) {
    $guardMode = 'warn';
}
$guardExemptPaths = $envList('PERF_GUARD_EXEMPT_PATHS', ['/health', '/__devtools/*']);
$guardExemptRoutes = $envList('PERF_GUARD_EXEMPT_ROUTES', []);

return [
    'enabled' => $budgetsEnabled || $guardsEnabled,
    'budgets_enabled' => $budgetsEnabled,
    'hard_fail' => $envBool('PERF_BUDGET_HARD_FAIL', false),
    'total_ms_warn' => $envInt('PERF_BUDGET_TOTAL_MS_WARN', 400),
    'total_ms_hard' => $envInt('PERF_BUDGET_TOTAL_MS_HARD', 1200),
    'sql_count_warn' => $envInt('PERF_BUDGET_SQL_COUNT_WARN', 40),
    'sql_count_hard' => $envInt('PERF_BUDGET_SQL_COUNT_HARD', 120),
    'sql_ms_warn' => $envInt('PERF_BUDGET_SQL_MS_WARN', 150),
    'sql_ms_hard' => $envInt('PERF_BUDGET_SQL_MS_HARD', 600),
    'guard_enabled' => $guardsEnabled,
    'guard_mode' => $guardMode,
    'guard_exempt_paths' => $guardExemptPaths,
    'guard_exempt_routes' => $guardExemptRoutes,
    'db_max_queries' => $envInt('PERF_DB_MAX_QUERIES', 80),
    'db_max_unique' => $envInt('PERF_DB_MAX_UNIQUE', 60),
    'db_max_total_ms' => $envInt('PERF_DB_MAX_TOTAL_MS', 250),
    'http_max_calls' => $envInt('PERF_HTTP_MAX_CALLS', 10),
    'http_max_total_ms' => $envInt('PERF_HTTP_MAX_TOTAL_MS', 500),
    'total_max_ms' => $envInt('PERF_TOTAL_MAX_MS', 1200),
    'db_max_queries_admin' => $envInt('PERF_DB_MAX_QUERIES_ADMIN', 120),
    'total_max_ms_admin' => $envInt('PERF_TOTAL_MAX_MS_ADMIN', 1600),
];
