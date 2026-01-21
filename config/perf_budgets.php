<?php
declare(strict_types=1);

return [
    'default_profile' => 'local',
    'profiles' => [
        'local' => [
            'defaults' => [
                'total_ms_warn' => 1500,
                'total_ms_hard' => 3000,
                'memory_mb_warn' => 96,
                'memory_mb_hard' => 160,
                'sql_count_warn' => 60,
                'sql_count_hard' => 140,
                'sql_unique_warn' => 40,
                'sql_unique_hard' => 90,
                'sql_dup_warn' => 2,
                'sql_dup_hard' => 8,
                'sql_ms_warn' => 250,
                'sql_ms_hard' => 700,
            ],
            'routes' => [
                '/admin/modules' => [
                    'sql_dup_warn' => 2,
                    'sql_dup_hard' => 4,
                    'sql_count_warn' => 25,
                    'sql_count_hard' => 40,
                ],
                '/admin/modules/details' => [
                    'sql_dup_warn' => 2,
                    'sql_dup_hard' => 4,
                    'sql_count_warn' => 20,
                    'sql_count_hard' => 40,
                ],
                '/admin/search*' => [
                    'sql_dup_warn' => 2,
                    'sql_dup_hard' => 4,
                    'sql_count_warn' => 35,
                    'sql_count_hard' => 70,
                ],
                '/api/v2/pages*' => [
                    'sql_dup_warn' => 1,
                    'sql_dup_hard' => 3,
                    'sql_count_warn' => 20,
                    'sql_count_hard' => 35,
                ],
                '/api/v2/menus*' => [
                    'sql_dup_warn' => 1,
                    'sql_dup_hard' => 3,
                    'sql_count_warn' => 18,
                    'sql_count_hard' => 35,
                ],
            ],
        ],
        'ci' => [
            'defaults' => [
                'total_ms_warn' => 900,
                'total_ms_hard' => 1800,
                'memory_mb_warn' => 80,
                'memory_mb_hard' => 130,
                'sql_count_warn' => 35,
                'sql_count_hard' => 80,
                'sql_unique_warn' => 22,
                'sql_unique_hard' => 45,
                'sql_dup_warn' => 0,
                'sql_dup_hard' => 2,
                'sql_ms_warn' => 180,
                'sql_ms_hard' => 450,
            ],
            'routes' => [
                '/admin/modules' => [
                    'sql_dup_warn' => 1,
                    'sql_dup_hard' => 3,
                    'sql_count_warn' => 20,
                    'sql_count_hard' => 40,
                ],
                '/admin/modules/details' => [
                    'sql_dup_warn' => 1,
                    'sql_dup_hard' => 3,
                    'sql_count_warn' => 18,
                    'sql_count_hard' => 35,
                ],
                '/admin/search*' => [
                    'sql_dup_warn' => 1,
                    'sql_dup_hard' => 3,
                    'sql_count_warn' => 26,
                    'sql_count_hard' => 55,
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
            ],
        ],
    ],
];
