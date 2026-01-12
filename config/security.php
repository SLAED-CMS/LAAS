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
$envFloat = static function (string $key, float $default) use ($env): float {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? (float) $value : $default;
};
$envList = static function (string $key, array $default) use ($env): array {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    $parts = array_filter(array_map('trim', explode(',', (string) $value)));
    return $parts !== [] ? array_values($parts) : $default;
};

$allowCdn = $envBool('CSP_ALLOW_CDN', false);
$cdnSources = $allowCdn ? ['https://cdn.jsdelivr.net'] : [];
$scriptExtra = $envList('CSP_SCRIPT_SRC_EXTRA', []);
$styleExtra = $envList('CSP_STYLE_SRC_EXTRA', []);
$connectExtra = $envList('CSP_CONNECT_SRC_EXTRA', []);
$unique = static function (array $values): array {
    $out = [];
    $seen = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '' || isset($seen[$value])) {
            continue;
        }
        $seen[$value] = true;
        $out[] = $value;
    }
    return $out;
};

return [
    'session' => [
        'driver' => $envString('SESSION_DRIVER', 'native'),
        'name' => $envString('SESSION_NAME', 'LAASID'),
        'secure' => $envBool('SESSION_SECURE', false),
        'httponly' => $envBool('SESSION_HTTPONLY', true),
        'samesite' => $envString('SESSION_SAMESITE', 'Lax'),
        'lifetime' => $envInt('SESSION_LIFETIME', 0),
        'domain' => $envString('SESSION_DOMAIN', ''),
        'timeout' => $envInt('SESSION_TIMEOUT', 7200),
        'redis' => [
            'url' => $envString('REDIS_URL', 'redis://127.0.0.1:6379/0'),
            'timeout' => $envFloat('REDIS_TIMEOUT', 1.5),
            'prefix' => $envString('REDIS_PREFIX', 'laas:sess:'),
        ],
    ],
    'hsts_enabled' => false,
    'hsts_max_age' => 31536000,
    'referrer_policy' => 'strict-origin-when-cross-origin',
    'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
    'frame_options' => 'DENY',
    'csp' => [
        'enabled' => true,
        'allow_cdn' => $allowCdn,
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => $unique(array_merge(
                ["'self'"],
                $cdnSources,
                $envBool('APP_DEBUG', false) ? ["'unsafe-inline'"] : [],
                $scriptExtra
            )),
            'style-src' => $unique(array_merge(
                ["'self'", "'unsafe-inline'"],
                $cdnSources,
                $styleExtra
            )),
            'font-src' => $unique(array_merge(["'self'", 'data:'], $cdnSources)),
            'img-src' => ["'self'", 'data:'],
            'connect-src' => $unique(array_merge(["'self'"], $cdnSources, $connectExtra)),
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
    'trust_proxy' => [
        'enabled' => $envBool('TRUST_PROXY_ENABLED', false),
        'ips' => $envList('TRUST_PROXY_IPS', []),
        'headers' => $envList('TRUST_PROXY_HEADERS', ['x-forwarded-for', 'x-forwarded-proto']),
    ],
];
