<?php
declare(strict_types=1);

return [
    'session' => [
        'name' => 'LAASID',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
        'lifetime' => 0,
        'domain' => '',
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
            'max' => 60,
        ],
        'login' => [
            'window' => 60,
            'max' => 10,
        ],
    ],
    'trusted_proxies' => [],
];
