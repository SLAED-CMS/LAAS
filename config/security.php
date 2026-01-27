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
$envEnum = static function (string $key, array $allowed, string $default) use ($env, $envString): string {
    $value = strtolower(trim($envString($key, $default)));
    return in_array($value, $allowed, true) ? $value : $default;
};

$cookieSecure = $envBool('SESSION_COOKIE_SECURE', $envBool('SESSION_SECURE', false));
$cookieHttpOnly = $envBool('SESSION_COOKIE_HTTPONLY', $envBool('SESSION_HTTPONLY', true));
$cookieSamesite = $envString('SESSION_COOKIE_SAMESITE', $envString('SESSION_SAMESITE', 'Lax'));
$cookieDomain = $envString('SESSION_COOKIE_DOMAIN', $envString('SESSION_DOMAIN', ''));
$idleTtl = $envInt('SESSION_IDLE_TTL', 0);
$absoluteTtl = $envInt('SESSION_ABSOLUTE_TTL', 0);

$allowCdn = $envBool('CSP_ALLOW_CDN', false);
$cspMode = $envEnum('CSP_MODE', ['enforce', 'report-only'], 'enforce');
$cdnSources = $allowCdn ? ['https://cdn.jsdelivr.net'] : [];
$scriptExtra = $envList('CSP_SCRIPT_SRC_EXTRA', []);
$styleExtra = $envList('CSP_STYLE_SRC_EXTRA', []);
$connectExtra = $envList('CSP_CONNECT_SRC_EXTRA', []);
$templateRawMode = $envEnum('TEMPLATE_RAW_MODE', ['strict', 'escape', 'allow'], 'escape');
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
        'idle_ttl' => $idleTtl,
        'absolute_ttl' => $absoluteTtl,
        'cookie_secure' => $cookieSecure,
        'cookie_httponly' => $cookieHttpOnly,
        'cookie_samesite' => $cookieSamesite,
        'cookie_domain' => $cookieDomain,
        'cookie_path' => '/',
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
        'mode' => $cspMode,
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
    'template' => [
        'raw_mode' => $templateRawMode,
    ],
    'template_raw_allowlist_path' => 'config/template_raw_allowlist.php',
    'reports_normalize_enabled' => $envBool('SECURITY_REPORTS_NORMALIZE_ENABLED', false),
    'ai_file_apply_allowlist_prefixes' => [
        'modules/',
        'themes/',
        'docs/',
        'storage/sandbox/modules/',
        'storage/sandbox/themes/',
        'storage/sandbox/docs/',
    ],
    'ai_plan_command_allowlist' => [
        'policy:check',
        'templates:raw:check',
        'templates:raw:scan',
        'theme:validate',
        'contracts:check',
        'preflight',
    ],
    'ai_provider' => 'local_demo',
    'ai_remote_enabled' => false,
    'ai_remote_allowlist' => [],
    'ai_remote_timeout_ms' => 8000,
    'ai_remote_max_request_bytes' => 200000,
    'ai_remote_max_response_bytes' => 300000,
    'ai_remote_endpoint' => '/v1/propose',
    'ai_remote_auth_header' => '',
    'rate_limit' => [
        'enabled' => $envBool('RATE_LIMIT_ENABLED', true),
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
