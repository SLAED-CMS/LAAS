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

return [
    'enabled' => $envBool('CACHE_ENABLED', true),
    'ttl_default' => $envInt('CACHE_TTL_DEFAULT', 60),
    'ttl_settings' => $envInt('CACHE_TTL_SETTINGS', 60),
    'ttl_permissions' => $envInt('CACHE_TTL_PERMISSIONS', 60),
    'ttl_menus' => $envInt('CACHE_TTL_MENUS', 60),
    'prefix' => $envString('CACHE_PREFIX', 'laas'),
];
