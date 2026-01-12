<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;

ContractRegistry::register('pages.show', [
    'route' => '/{slug}',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '200' => [
            'data' => [
                'page' => [
                    'id' => 'int',
                    'slug' => 'string',
                    'title' => 'string',
                    'content' => 'string',
                    'updated_at' => 'string',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'pages.show',
            ],
        ],
        '404' => [
            'error' => 'not_found',
            'meta' => [
                'format' => 'json',
                'route' => 'pages.show',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.modules.index', [
    'route' => '/admin/modules',
    'methods' => ['GET'],
    'rbac' => 'admin.modules.manage',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'name' => 'string',
                        'enabled' => 'bool',
                        'version' => 'string|null',
                        'type' => 'core|module|internal',
                        'protected' => 'bool',
                    ],
                ],
                'counts' => [
                    'total' => 'int',
                    'enabled' => 'int',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.index',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.index',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.modules.toggle', [
    'route' => '/admin/modules/toggle',
    'methods' => ['POST'],
    'rbac' => 'admin.modules.manage',
    'responses' => [
        '200' => [
            'data' => [
                'name' => 'string',
                'enabled' => 'bool',
                'protected' => 'bool',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.toggle',
            ],
        ],
        '400' => [
            'error' => 'protected_module',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.toggle',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.settings.index', [
    'route' => '/admin/settings',
    'methods' => ['GET'],
    'rbac' => 'admin.settings.manage',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'key' => 'string',
                        'value' => 'string',
                        'source' => 'DB|CONFIG',
                        'type' => 'string',
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.index',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.index',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.settings.save', [
    'route' => '/admin/settings',
    'methods' => ['POST'],
    'rbac' => 'admin.settings.manage',
    'responses' => [
        '200' => [
            'data' => [
                'saved' => 'bool',
                'updated' => [
                    [
                        'key' => 'string',
                        'value' => 'string',
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.save',
            ],
        ],
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.save',
            ],
        ],
    ],
]);
