<?php
declare(strict_types=1);

$env = $_ENV;
$envString = static function (string $key, string $default) use ($env): string {
    $value = $env[$key] ?? getenv($key);
    if ($value === null || $value === '') {
        return $default;
    }
    return (string) $value;
};
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? getenv($key);
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? (int) $value : $default;
};
$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env[$key] ?? getenv($key);
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

$appEnv = strtolower($envString('APP_ENV', 'dev'));
$defaultSafeMode = in_array($appEnv, ['prod', 'production'], true) ? 'block' : 'warn';
$safeMode = strtolower($envString('DB_MIGRATIONS_SAFE_MODE', $defaultSafeMode));
if (!in_array($safeMode, ['off', 'warn', 'block'], true)) {
    $safeMode = $defaultSafeMode;
}

return [
    'driver' => $envString('DB_DRIVER', $envString('DB_CONNECTION', 'sqlite')),
    'host' => $envString('DB_HOST', '127.0.0.1'),
    'port' => $envInt('DB_PORT', 3306),
    'database' => $envString('DB_DATABASE', $envString('DB_NAME', ':memory:')),
    'username' => $envString('DB_USERNAME', $envString('DB_USER', 'root')),
    'password' => $envString('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'options' => [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ],
    'migrations' => [
        'safe_mode' => $safeMode,
        'allow_destructive' => $envBool('ALLOW_DESTRUCTIVE_MIGRATIONS', false),
    ],
];
