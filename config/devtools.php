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
    'show_secrets' => $envBool('DEVTOOLS_SHOW_SECRETS', false),
    'budgets' => [
        'total_time_warn' => $envInt('DEVTOOLS_BUDGET_TOTAL_WARN', 200),
        'total_time_bad' => $envInt('DEVTOOLS_BUDGET_TOTAL_BAD', 500),
        'slow_sql_warn' => $envInt('DEVTOOLS_BUDGET_SLOW_SQL_WARN', 50),
        'slow_sql_bad' => $envInt('DEVTOOLS_BUDGET_SLOW_SQL_BAD', 200),
        'slow_http_warn' => $envInt('DEVTOOLS_BUDGET_SLOW_HTTP_WARN', 200),
        'slow_http_bad' => $envInt('DEVTOOLS_BUDGET_SLOW_HTTP_BAD', 1000),
    ],
];
