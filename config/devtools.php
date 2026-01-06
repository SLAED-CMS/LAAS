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
    'terminal' => [
        'bg' => '#1e1f29',
        'panel_bg' => '#1b1c25',
        'text' => '#d6d6d6',
        'muted' => '#8a8da8',
        'border' => 'rgba(255,255,255,0.08)',
        'info' => '#82aaff',
        'ok' => '#73c991',
        'warn' => '#ffcb6b',
        'err' => '#ff6c6b',
        'num' => '#89ddff',
        'sql' => '#c792ea',
        'http_get' => '#82aaff',
        'http_post' => '#c3e88d',
        'http_put' => '#ffcb6b',
        'http_delete' => '#ff6c6b',
        'font_size' => 16,
        'line_height' => 1.25,
        'font_family' => 'Verdana, Tahoma, monospace',
        'padding' => 8,
        'btn_bg' => 'rgba(255,255,255,0.03)',
        'btn_bg_hover' => 'rgba(255,255,255,0.07)',
    ],
];
