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
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? (int) $value : $default;
};

return [
    'driver' => $envString('DB_CONNECTION', $envString('DB_DRIVER', 'sqlite')),
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
];
