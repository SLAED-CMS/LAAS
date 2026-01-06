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
$envCsv = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || trim((string) $value) === '') {
        return $default;
    }
    $parts = array_map('trim', explode(',', (string) $value));
    return array_values(array_filter($parts, static fn ($item): bool => $item !== ''));
};
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? (int) $value : $default;
};

return [
    'enabled' => $envBool('API_ENABLED', true),
    'token_issue_mode' => $envString('API_TOKEN_ISSUE_MODE', 'admin'),
    'cors' => [
        'enabled' => $envBool('API_CORS_ENABLED', false),
        'origins' => $envCsv('API_CORS_ORIGINS', []),
        'methods' => $envCsv('API_CORS_METHODS', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']),
        'headers' => $envCsv('API_CORS_HEADERS', ['Authorization', 'Content-Type', 'X-Requested-With']),
        'max_age' => $envInt('API_CORS_MAX_AGE', 600),
    ],
];
