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

ContractRegistry::register('admin.users.index', [
    'route' => '/admin/users',
    'methods' => ['GET'],
    'rbac' => 'users.manage',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'id' => 'int',
                        'username' => 'string',
                        'roles' => ['string'],
                        'active' => 'bool',
                        'created_at' => 'string',
                    ],
                ],
                'pagination' => [
                    'limit' => 'int',
                    'offset' => 'int',
                    'total' => 'int',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.index',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.index',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.users.toggle', [
    'route' => '/admin/users/status',
    'methods' => ['POST'],
    'rbac' => 'users.manage',
    'responses' => [
        '200' => [
            'data' => [
                'id' => 'int',
                'active' => 'bool',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.toggle',
            ],
        ],
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.toggle',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.toggle',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.media.index', [
    'route' => '/admin/media',
    'methods' => ['GET'],
    'rbac' => 'media.view',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'id' => 'int',
                        'name' => 'string',
                        'mime' => 'string',
                        'size' => 'int',
                        'hash' => 'string|null',
                        'disk' => 'string',
                        'created_at' => 'string',
                    ],
                ],
                'counts' => [
                    'total' => 'int',
                    'page' => 'int',
                    'total_pages' => 'int',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.index',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.index',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.media.upload', [
    'route' => '/admin/media/upload',
    'methods' => ['POST'],
    'rbac' => 'media.upload',
    'responses' => [
        '201' => [
            'data' => [
                'id' => 'int',
                'mime' => 'string',
                'size' => 'int',
                'hash' => 'string',
                'deduped' => 'bool',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.upload',
            ],
        ],
        '400' => [
            'error' => 'invalid_mime',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.upload',
            ],
        ],
        '413' => [
            'error' => 'file_too_large',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.upload',
            ],
        ],
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.upload',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.upload',
            ],
        ],
    ],
]);

ContractRegistry::register('media.show', [
    'route' => '/media/{id}',
    'methods' => ['GET'],
    'rbac' => 'media.view',
    'responses' => [
        '200' => [
            'data' => [
                'id' => 'int',
                'mime' => 'string',
                'size' => 'int',
                'hash' => 'string|null',
                'mode' => 'inline|attachment',
                'signed_url' => 'string|null',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'media.show',
            ],
        ],
        '404' => [
            'error' => 'not_found',
            'meta' => [
                'format' => 'json',
                'route' => 'media.show',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'media.show',
            ],
        ],
    ],
]);
