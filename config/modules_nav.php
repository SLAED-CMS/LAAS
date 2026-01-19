<?php
declare(strict_types=1);

return [
    'pinned' => [
        'Pages',
        'Media',
        'Users',
    ],
    'modules' => [
        'Admin' => [
            'group' => 'core',
            'nav_priority' => 10,
            'nav_label' => 'Admin',
            'nav_badge' => 'CORE',
        ],
        'Users' => [
            'group' => 'core',
            'nav_priority' => 20,
            'nav_label' => 'Users',
            'nav_badge' => 'CORE',
        ],
        'Pages' => [
            'group' => 'content',
            'nav_priority' => 10,
            'nav_label' => 'Pages',
        ],
        'Menu' => [
            'group' => 'content',
            'nav_priority' => 20,
            'nav_label' => 'Menus',
        ],
        'Media' => [
            'group' => 'content',
            'nav_priority' => 30,
            'nav_label' => 'Media',
        ],
        'System' => [
            'group' => 'system',
            'nav_priority' => 10,
            'nav_label' => 'System',
            'nav_badge' => 'SYSTEM',
        ],
        'Api' => [
            'group' => 'api',
            'nav_priority' => 20,
            'nav_label' => 'API',
            'nav_badge' => 'API',
        ],
        'Audit' => [
            'group' => 'system',
            'nav_priority' => 30,
            'nav_label' => 'Audit',
        ],
        'Changelog' => [
            'group' => 'dev',
            'nav_priority' => 10,
            'nav_label' => 'Changelog',
        ],
        'DevTools' => [
            'group' => 'dev',
            'nav_priority' => 20,
            'nav_label' => 'DevTools',
        ],
        'Demo' => [
            'group' => 'demo',
            'nav_priority' => 10,
            'nav_label' => 'Demo',
        ],
        'DemoBlog' => [
            'group' => 'demo',
            'nav_priority' => 20,
            'nav_label' => 'Demo Blog',
        ],
        'DemoEnv' => [
            'group' => 'demo',
            'nav_priority' => 30,
            'nav_label' => 'Demo Env',
        ],
        'AI' => [
            'group' => 'demo',
            'nav_priority' => 40,
            'nav_label' => 'AI',
            'nav_badge' => 'DEMO',
        ],
    ],
    'actions_nav_default' => ['Open', 'New', 'Details'],
    'actions_allowlist' => [
        '/admin',
        '/admin/pages',
        '/admin/pages/new',
        '/admin/media',
        '/admin/menus',
        '/admin/users',
        '/admin/modules*',
        '/admin/changelog',
        '/admin/ops',
        '/admin/security-reports',
        '/admin/audit',
        '/admin/api-tokens',
        '/admin/ai',
    ],
];
