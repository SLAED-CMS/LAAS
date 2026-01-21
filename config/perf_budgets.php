<?php
declare(strict_types=1);

$baseDefaults = [
    'sql_count_warn' => 35,
    'sql_count_hard' => 80,
    'sql_unique_warn' => 22,
    'sql_unique_hard' => 45,
    'sql_dup_warn' => 0,
    'sql_dup_hard' => 2,
    'sql_ms_warn' => 180,
    'sql_ms_hard' => 450,
];

$baseRoutes = [
    '/admin/modules' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 4,
        'sql_count_warn' => 20,
        'sql_count_hard' => 40,
    ],
    '/admin/modules/details' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 4,
        'sql_count_warn' => 18,
        'sql_count_hard' => 35,
    ],
    '/admin/search*' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 5,
        'sql_count_warn' => 26,
        'sql_count_hard' => 55,
    ],
    '/api/v2/pages:304' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 3,
        'sql_count_warn' => 14,
        'sql_count_hard' => 30,
    ],
    '/api/v2/menus:304' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 3,
        'sql_count_warn' => 12,
        'sql_count_hard' => 28,
    ],
    '/api/v2/pages*' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 3,
        'sql_count_warn' => 14,
        'sql_count_hard' => 30,
    ],
    '/api/v2/menus*' => [
        'sql_dup_warn' => 1,
        'sql_dup_hard' => 3,
        'sql_count_warn' => 12,
        'sql_count_hard' => 28,
    ],
];

return [
    'default_profile' => 'ci',
    'profiles' => [
        'ci' => [
            'defaults' => array_merge($baseDefaults, [
                'total_ms_warn' => 1100,
                'total_ms_hard' => 2100,
                'memory_mb_warn' => 90,
                'memory_mb_hard' => 150,
            ]),
            'routes' => $baseRoutes,
        ],
        'strict' => [
            'defaults' => array_merge($baseDefaults, [
                'total_ms_warn' => 800,
                'total_ms_hard' => 1600,
                'memory_mb_warn' => 70,
                'memory_mb_hard' => 120,
            ]),
            'routes' => $baseRoutes,
        ],
    ],
];
