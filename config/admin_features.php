<?php
declare(strict_types=1);

$env = $_ENV;
$envBool = static function (string $key, ?bool $default = null) use ($env): ?bool {
    $value = $env[$key] ?? getenv($key);
    if ($value === null || $value === '' || $value === false) {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

$appEnv = strtolower((string) ($env['APP_ENV'] ?? getenv('APP_ENV') ?? 'dev'));
$isProd = in_array($appEnv, ['prod', 'production'], true);
$appDebug = $envBool('APP_DEBUG', null);
if ($appDebug === null) {
    $appDebug = true;
}

$defaultEnabled = !$isProd && $appDebug === true;
$resolveFlag = static function (string $key) use ($envBool, $defaultEnabled): bool {
    $override = $envBool($key, null);
    return $override ?? $defaultEnabled;
};

return [
    'ADMIN_FEATURE_PALETTE' => $resolveFlag('ADMIN_FEATURE_PALETTE'),
    'ADMIN_FEATURE_BLOCKS_STUDIO' => $resolveFlag('ADMIN_FEATURE_BLOCKS_STUDIO'),
    'ADMIN_FEATURE_THEME_INSPECTOR' => $resolveFlag('ADMIN_FEATURE_THEME_INSPECTOR'),
    'ADMIN_FEATURE_HEADLESS_PLAYGROUND' => $resolveFlag('ADMIN_FEATURE_HEADLESS_PLAYGROUND'),
];
