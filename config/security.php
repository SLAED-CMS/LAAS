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
    return is_numeric($value) ? (int) $value : $default;
};

return [
    'session' => [
        'name' => $envString('SESSION_NAME', 'LAASID'),
        'secure' => $envBool('SESSION_SECURE', false),
        'httponly' => $envBool('SESSION_HTTPONLY', true),
        'samesite' => $envString('SESSION_SAMESITE', 'Lax'),
        'lifetime' => $envInt('SESSION_LIFETIME', 0),
        'domain' => $envString('SESSION_DOMAIN', ''),
    ],
    'hsts_enabled' => false,
    'hsts_max_age' => 31536000,
    'referrer_policy' => 'strict-origin-when-cross-origin',
    'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
    'frame_options' => 'DENY',
    'csp' => [
        'enabled' => true,
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", 'https://cdn.jsdelivr.net'],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://cdn.jsdelivr.net'],
            'font-src' => ["'self'", 'data:', 'https://cdn.jsdelivr.net'],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", 'https://cdn.jsdelivr.net'],
            'frame-ancestors' => ["'none'"],
        ],
    ],
    'rate_limit' => [
        'api' => [
            'window' => 60,
            'max' => $envInt('API_RATE_LIMIT_PER_MINUTE', 120),
            'per_minute' => $envInt('API_RATE_LIMIT_PER_MINUTE', 120),
            'burst' => $envInt('API_RATE_LIMIT_BURST', 30),
        ],
        'login' => [
            'window' => 60,
            'max' => 10,
        ],
        'media_upload' => [
            'window' => 300,
            'max' => 10,
        ],
    ],
    'trusted_proxies' => [],
];
