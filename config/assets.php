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

return [
    'base_url' => $envString('ASSETS_BASE_URL', '/assets'),
    'version' => $envString('ASSETS_VERSION', $envString('APP_VERSION', '')),
    'cache_busting' => $envBool('ASSETS_CACHE_BUSTING', true),
    'css' => [
        'bootstrap' => [
            'path' => 'vendor/bootstrap/bootstrap.min.css',
        ],
        'bootstrap-icons' => [
            'path' => 'vendor/bootstrap-icons/bootstrap-icons.min.css',
        ],
        'app' => [
            'path' => 'app/app.css',
        ],
        'admin' => [
            'path' => 'admin.css',
        ],
    ],
    'js' => [
        'htmx' => [
            'path' => 'vendor/htmx/htmx.min.js',
            'defer' => true,
        ],
        'bootstrap' => [
            'path' => 'vendor/bootstrap/bootstrap.bundle.min.js',
            'defer' => true,
        ],
        'app' => [
            'path' => 'app/app.js',
            'defer' => true,
        ],
        'admin' => [
            'path' => 'admin.js',
            'defer' => true,
        ],
    ],
];
