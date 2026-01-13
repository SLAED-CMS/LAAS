<?php
declare(strict_types=1);

$env = $_ENV;
$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env[$key] ?? null;
    if ($value === null || $value === '') {
        return $default;
    }
    return is_numeric($value) ? (int) $value : $default;
};

return [
    'profiles' => [
        'default' => [
            'window' => 60,
            'max' => 120,
        ],
        'api_default' => [
            'window' => 60,
            'max' => $envInt('API_RATE_LIMIT_PER_MINUTE', 120),
            'burst' => $envInt('API_RATE_LIMIT_BURST', 30),
        ],
        'auth_login' => [
            'window' => 60,
            'max' => 10,
        ],
        'media_upload' => [
            'window' => 300,
            'max' => 10,
        ],
        'csp_report' => [
            'window' => 60,
            'max' => 60,
        ],
        'admin_write' => [
            'window' => 60,
            'max' => 30,
        ],
    ],
    'routes' => [
        [
            'path' => '/login',
            'methods' => ['POST'],
            'profile' => 'auth_login',
        ],
        [
            'path' => '/admin/login',
            'methods' => ['POST'],
            'profile' => 'auth_login',
        ],
        [
            'path' => '/__csp/report',
            'methods' => ['POST'],
            'profile' => 'csp_report',
        ],
        [
            'path' => '/admin',
            'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],
            'match' => 'prefix',
            'profile' => 'admin_write',
        ],
    ],
    'route_names' => [
        'admin.api_tokens.create' => 'admin_write',
        'admin.api_tokens.revoke' => 'admin_write',
        'admin.modules.toggle' => 'admin_write',
        'admin.settings.save' => 'admin_write',
    ],
    'fallback' => 'default',
];
