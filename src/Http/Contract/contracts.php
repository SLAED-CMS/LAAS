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
    'example_ok' => [
        'fixture' => 'pages.show',
        'payload' => [
            'data' => [
                'page' => [
                    'id' => 1,
                    'slug' => 'hello',
                    'title' => 'Hello',
                    'content' => 'Body',
                    'updated_at' => '2026-01-01 00:00:00',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'pages.show',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('api.auth.forbidden_scope', [
    'route' => '/api/v1/me',
    'methods' => ['GET'],
    'rbac' => 'api',
    'responses' => [
        '403' => [
            'error' => 'api.auth.forbidden_scope',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/me',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'api.auth.forbidden_scope',
        'payload' => [
            'error' => [
                'code' => 'E_RBAC_DENIED',
                'message' => 'Insufficient token scope.',
            ],
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/me',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
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
    'example_ok' => [
        'fixture' => 'admin.modules.index',
        'payload' => [
            'data' => [
                'items' => [
                    [
                        'name' => 'System',
                        'enabled' => true,
                        'version' => '1.0.0',
                        'type' => 'core',
                        'protected' => true,
                    ],
                ],
                'counts' => [
                    'total' => 1,
                    'enabled' => 1,
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
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
    'example_error' => [
        'fixture' => 'admin.settings.save.validation_failed',
        'payload' => [
            'error' => [
                'code' => 'E_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => [
                    'fields' => [
                        'site_name' => ['invalid'],
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.save',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.api_tokens.index', [
    'route' => '/admin/api-tokens',
    'methods' => ['GET'],
    'rbac' => 'api_tokens.view',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'id' => 'int',
                        'name' => 'string',
                        'token_prefix' => 'string',
                        'scopes' => ['string'],
                        'last_used_at' => 'string|null',
                        'expires_at' => 'string|null',
                        'revoked_at' => 'string|null',
                        'created_at' => 'string',
                        'status' => 'active|expired|revoked',
                    ],
                ],
                'counts' => [
                    'total' => 'int',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.index',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.index',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.api_tokens.index',
        'payload' => [
            'data' => [
                'items' => [
                    [
                        'id' => 1,
                        'name' => 'CLI',
                        'token_prefix' => 'ABCDEF123456',
                        'scopes' => ['admin.read', 'admin.write'],
                        'last_used_at' => '2026-01-01 00:00:00',
                        'expires_at' => null,
                        'revoked_at' => null,
                        'created_at' => '2026-01-01 00:00:00',
                        'status' => 'active',
                    ],
                ],
                'counts' => [
                    'total' => 1,
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.api_tokens.create', [
    'route' => '/admin/api-tokens',
    'methods' => ['POST'],
    'rbac' => 'api_tokens.create',
    'responses' => [
        '201' => [
            'data' => [
                'token_id' => 'int',
                'name' => 'string',
                'token_prefix' => 'string',
                'scopes' => ['string'],
                'expires_at' => 'string|null',
                'token_once' => 'string',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.create',
            ],
        ],
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.create',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.create',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.api_tokens.create',
        'payload' => [
            'data' => [
                'token_id' => 1,
                'name' => 'CLI',
                'token_prefix' => 'ABCDEF123456',
                'scopes' => ['admin.read'],
                'expires_at' => null,
                'token_once' => 'LAAS_ABCDEF123456.S3CR3T',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.create',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.api_tokens.revoke', [
    'route' => '/admin/api-tokens/revoke',
    'methods' => ['POST'],
    'rbac' => 'api_tokens.revoke',
    'responses' => [
        '200' => [
            'data' => [
                'revoked' => 'bool',
                'token_id' => 'int',
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.revoke',
            ],
        ],
        '404' => [
            'error' => 'not_found',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.revoke',
            ],
        ],
        '403' => [
            'error' => 'forbidden',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.revoke',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.api_tokens.revoke',
        'payload' => [
            'data' => [
                'revoked' => true,
                'token_id' => 1,
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.api_tokens.revoke',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
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
    'example_ok' => [
        'fixture' => 'admin.users.index',
        'payload' => [
            'data' => [
                'items' => [
                    [
                        'id' => 1,
                        'username' => 'admin',
                        'roles' => ['admin'],
                        'active' => true,
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ],
                'pagination' => [
                    'limit' => 50,
                    'offset' => 0,
                    'total' => 1,
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.users.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
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
    'example_ok' => [
        'fixture' => 'admin.media.index',
        'payload' => [
            'data' => [
                'items' => [
                    [
                        'id' => 10,
                        'name' => 'photo.jpg',
                        'mime' => 'image/jpeg',
                        'size' => 12345,
                        'hash' => 'abc123',
                        'disk' => 'local',
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                ],
                'counts' => [
                    'total' => 1,
                    'page' => 1,
                    'total_pages' => 1,
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.media.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
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
    'example_ok' => [
        'fixture' => 'media.show',
        'payload' => [
            'data' => [
                'id' => 10,
                'mime' => 'image/jpeg',
                'size' => 12345,
                'hash' => 'abc123',
                'mode' => 'inline',
                'signed_url' => null,
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'media.show',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);
