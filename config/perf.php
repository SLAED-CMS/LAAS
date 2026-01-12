<?php
declare(strict_types=1);

$env = $_ENV;
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

return [
    'enabled' => $envBool('PERF_BUDGET_ENABLED', true),
    'hard_fail' => $envBool('PERF_BUDGET_HARD_FAIL', false),
    'total_ms_warn' => $envInt('PERF_BUDGET_TOTAL_MS_WARN', 400),
    'total_ms_hard' => $envInt('PERF_BUDGET_TOTAL_MS_HARD', 1200),
    'sql_count_warn' => $envInt('PERF_BUDGET_SQL_COUNT_WARN', 40),
    'sql_count_hard' => $envInt('PERF_BUDGET_SQL_COUNT_HARD', 120),
    'sql_ms_warn' => $envInt('PERF_BUDGET_SQL_MS_WARN', 150),
    'sql_ms_hard' => $envInt('PERF_BUDGET_SQL_MS_HARD', 600),
];
