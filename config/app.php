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
$envStringAllowEmpty = static function (string $key, string $default) use ($env): string {
    if (!array_key_exists($key, $env)) {
        return $default;
    }
    $value = $env[$key];
    if ($value === null) {
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
$envList = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    return $parts !== [] ? array_values($parts) : $default;
};
$envListAllowEmpty = static function (string $key, array $default) use ($env): array {
    if (!array_key_exists($key, $env)) {
        return $default;
    }
    $value = $env[$key];
    if ($value === null) {
        return $default;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }
    $parts = array_filter(array_map('trim', explode(',', $value)));
    return $parts !== [] ? array_values($parts) : [];
};

return [
    'name' => 'LAAS',
    'version' => $envString('APP_VERSION', '4.0.0-dev'),
    'key' => $envString('APP_KEY', ''),
    'env' => $envString('APP_ENV', 'dev'),
    'debug' => $envBool('APP_DEBUG', true),
    'health_write_check' => $envBool('HEALTH_WRITE_CHECK', false),
    'devtools' => [
        'enabled' => $envBool('DEVTOOLS_ENABLED', false),
        'collect_db' => $envBool('DEVTOOLS_COLLECT_DB', true),
        'collect_request' => $envBool('DEVTOOLS_COLLECT_REQUEST', true),
        'collect_logs' => $envBool('DEVTOOLS_COLLECT_LOGS', false),
    ],
    'db_profile' => [
        'store_sql' => $envBool('DB_PROFILE_STORE_SQL', strtolower($envString('APP_ENV', 'dev')) !== 'prod'),
    ],
    'default_locale' => 'en',
    'locales' => ['en', 'de', 'ru', 'fr', 'es', 'pt', 'uk', 'pl', 'zh', 'hi', 'ar', 'bn', 'ur', 'sw', 'id'],
    'rtl_locales' => ['ar', 'ur'],
    'theme' => 'default',
    'admin_seed_enabled' => $envBool('ADMIN_SEED_ENABLED', true),
    'admin_seed_password' => $envString('ADMIN_SEED_PASSWORD', 'change-me'),
    'read_only' => $envBool('APP_READ_ONLY', false),
    'enforce_ui_tokens' => $envBool('APP_ENFORCE_UI_TOKENS', false),
    'headless_mode' => $envBool('APP_HEADLESS', $envBool('HEADLESS_MODE', false)),
    'headless_html_allowlist' => $envListAllowEmpty('APP_HEADLESS_HTML_ALLOWLIST', ['/login', '/logout', '/admin']),
    'headless_html_override_param' => $envStringAllowEmpty('APP_HEADLESS_HTML_OVERRIDE_PARAM', '_html'),
    'middleware' => [],
    'home_showcase_enabled' => $envBool('HOME_SHOWCASE_ENABLED', true),
    'home_showcase_blocks' => $envList('HOME_SHOWCASE_BLOCKS', []),
];
