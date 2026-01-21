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
$appDebug = $envBool('APP_DEBUG', true);

$defaultEnabled = $appDebug && !$isProd;
$resolveDevtoolsFlag = static function (string $primaryEnv, ?string $legacyEnv) use ($envBool, $defaultEnabled, $appDebug, $isProd): bool {
    if (!$appDebug || $isProd) {
        return false;
    }
    $override = $envBool($primaryEnv, null);
    if ($override === null && $legacyEnv !== null) {
        $override = $envBool($legacyEnv, null);
    }
    return $override ?? $defaultEnabled;
};

return [
    'devtools_palette' => $resolveDevtoolsFlag('DEVTOOLS_PALETTE', 'ADMIN_FEATURE_PALETTE'),
    'devtools_blocks_studio' => $resolveDevtoolsFlag('DEVTOOLS_BLOCKS_STUDIO', 'ADMIN_FEATURE_BLOCKS_STUDIO'),
    'devtools_theme_inspector' => $resolveDevtoolsFlag('DEVTOOLS_THEME_INSPECTOR', 'ADMIN_FEATURE_THEME_INSPECTOR'),
    'devtools_headless_playground' => $resolveDevtoolsFlag('DEVTOOLS_HEADLESS_PLAYGROUND', 'ADMIN_FEATURE_HEADLESS_PLAYGROUND'),
];
