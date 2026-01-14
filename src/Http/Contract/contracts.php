<?php
declare(strict_types=1);

use Laas\Http\Contract\ContractRegistry;

/**
 * @return array{type: string, title: string, status: int, instance: string}
 */
function contract_problem(string $key, int $status, string $title): array
{
    return [
        'type' => 'laas:error/' . $key,
        'title' => $title,
        'status' => $status,
        'instance' => 'req-1',
    ];
}

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
            'error' => 'error.not_found',
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
            'data' => null,
            'error' => [
                'code' => 'E_RBAC_DENIED',
                'message' => 'Insufficient token scope.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'api.auth.forbidden_scope',
                    'message' => 'Insufficient token scope.',
                ],
                'problem' => contract_problem('api.auth.forbidden_scope', 403, 'Error.'),
                'route' => '/api/v1/me',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('api.auth.failed', [
    'route' => '/api/v1/me',
    'methods' => ['GET'],
    'rbac' => 'api',
    'responses' => [
        '401' => [
            'error' => 'auth.invalid_token',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/me',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'api.auth.failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_API_TOKEN_INVALID',
                'message' => 'Invalid token',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'auth.invalid_token',
                    'message' => 'Invalid token',
                ],
                'problem' => contract_problem('auth.invalid_token', 401, 'Error.'),
                'route' => '/api/v1/me',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('error.rbac_denied', [
    'route' => 'admin.modules.index',
    'methods' => ['GET'],
    'rbac' => 'admin.modules.manage',
    'responses' => [
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.modules.index',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'error.rbac_denied',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_RBAC_DENIED',
                'message' => 'Access denied.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.rbac_denied',
                    'message' => 'Access denied.',
                ],
                'problem' => contract_problem('error.rbac_denied', 403, 'Access denied.'),
                'route' => 'admin.modules.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('error.auth_required', [
    'route' => '/admin',
    'methods' => ['GET'],
    'rbac' => 'admin.access',
    'responses' => [
        '401' => [
            'error' => 'error.auth_required',
            'meta' => [
                'format' => 'json',
                'route' => '/admin',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'error.auth_required',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_AUTH_REQUIRED',
                'message' => 'Authentication required.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.auth_required',
                    'message' => 'Authentication required.',
                ],
                'problem' => contract_problem('error.auth_required', 401, 'Error.'),
                'route' => '/admin',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('error.invalid_request', [
    'route' => '/api/v1/pages',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '400' => [
            'error' => 'error.invalid_request',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/pages',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'error.invalid_request',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'Invalid request.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.invalid_request',
                    'message' => 'Invalid request.',
                ],
                'problem' => contract_problem('error.invalid_request', 400, 'Error.'),
                'route' => '/api/v1/pages',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('error.not_found', [
    'route' => '/api/v1/pages/9999',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/pages/9999',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'error.not_found',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_NOT_FOUND',
                'message' => 'Not Found.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.not_found',
                    'message' => 'Not Found.',
                ],
                'problem' => contract_problem('error.not_found', 404, 'Error.'),
                'route' => '/api/v1/pages/9999',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('security.csrf_failed', [
    'route' => 'admin.settings.save',
    'methods' => ['POST'],
    'rbac' => 'admin.settings.manage',
    'responses' => [
        '403' => [
            'error' => 'security.csrf_failed',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.save',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'security.csrf_failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_CSRF_INVALID',
                'message' => 'CSRF validation failed.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'security.csrf_failed',
                    'message' => 'CSRF validation failed.',
                ],
                'problem' => contract_problem('security.csrf_failed', 403, 'CSRF validation failed.'),
                'route' => 'admin.settings.save',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.login.validation_failed', [
    'route' => '/login',
    'methods' => ['POST'],
    'rbac' => 'public',
    'responses' => [
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'route' => '/login',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'admin.login.validation_failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => [
                    'fields' => [
                        'username' => ['invalid'],
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.validation_failed',
                    'message' => 'Validation failed.',
                ],
                'problem' => contract_problem('error.validation_failed', 422, 'Error.'),
                'route' => '/login',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.pages.save', [
    'route' => '/admin/pages/save',
    'methods' => ['POST'],
    'rbac' => 'pages.edit',
    'responses' => [
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.pages.save',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'admin.pages.save.validation_failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => [
                    'fields' => [
                        'title' => ['invalid'],
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.validation_failed',
                    'message' => 'Validation failed.',
                ],
                'problem' => contract_problem('error.validation_failed', 422, 'Error.'),
                'events' => [
                    [
                        'type' => 'danger',
                        'message_key' => 'toast.validation_failed',
                        'message' => 'Validation failed.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.pages.save',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('rate_limited', [
    'route' => '/api/v1/pages',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '429' => [
            'error' => 'rate_limited',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/pages',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'rate_limited',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_RATE_LIMITED',
                'message' => 'Rate limit exceeded.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'rate_limited',
                    'message' => 'Rate limit exceeded.',
                ],
                'problem' => [
                    'type' => 'laas:error/rate_limited',
                    'title' => 'Too many requests.',
                    'status' => 429,
                    'instance' => 'req-1',
                ],
                'route' => '/api/v1/pages',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('api.validation_failed', [
    'route' => '/api/v1/pages',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '422' => [
            'error' => 'validation_failed',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/pages',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'api.validation_failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => [
                    'fields' => [
                        'q' => ['invalid'],
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'error.validation_failed',
                    'message' => 'Validation failed.',
                ],
                'problem' => contract_problem('error.validation_failed', 422, 'Error.'),
                'route' => '/api/v1/pages',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('system.read_only', [
    'route' => 'admin.settings.save',
    'methods' => ['POST'],
    'rbac' => 'admin.settings.manage',
    'responses' => [
        '503' => [
            'error' => 'read_only',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.settings.save',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'system.read_only',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_READ_ONLY',
                'message' => 'Read-only mode: write operations are disabled.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'system.read_only',
                    'message' => 'Read-only mode: write operations are disabled.',
                ],
                'problem' => contract_problem('system.read_only', 503, 'Error.'),
                'route' => 'admin.settings.save',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('service_unavailable', [
    'route' => '/api/v1/me',
    'methods' => ['GET'],
    'rbac' => 'api',
    'responses' => [
        '503' => [
            'error' => 'service_unavailable',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/me',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'service_unavailable',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_SERVICE_UNAVAILABLE',
                'message' => 'Service Unavailable.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'service_unavailable',
                    'message' => 'Service Unavailable.',
                ],
                'problem' => [
                    'type' => 'laas:error/service_unavailable',
                    'title' => 'Service unavailable.',
                    'status' => 503,
                    'instance' => 'req-1',
                ],
                'route' => '/api/v1/me',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('http.payload_too_large', [
    'route' => '/api/v1/ping',
    'methods' => ['POST'],
    'rbac' => 'public',
    'responses' => [
        '413' => [
            'error' => 'http.payload_too_large',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/ping',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'http.payload_too_large',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'Payload too large.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'http.payload_too_large',
                    'message' => 'Payload too large.',
                ],
                'problem' => contract_problem('http.payload_too_large', 413, 'Payload too large.'),
                'route' => '/api/v1/ping',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('http.uri_too_long', [
    'route' => '/api/v1/ping',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '414' => [
            'error' => 'http.uri_too_long',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/ping',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'http.uri_too_long',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'URI too long.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'http.uri_too_long',
                    'message' => 'URI too long.',
                ],
                'problem' => contract_problem('http.uri_too_long', 414, 'Error.'),
                'route' => '/api/v1/ping',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('http.headers_too_large', [
    'route' => '/api/v1/ping',
    'methods' => ['GET'],
    'rbac' => 'public',
    'responses' => [
        '431' => [
            'error' => 'http.headers_too_large',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/ping',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'http.headers_too_large',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'Request headers too large.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'http.headers_too_large',
                    'message' => 'Request headers too large.',
                ],
                'problem' => contract_problem('http.headers_too_large', 431, 'Error.'),
                'route' => '/api/v1/ping',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('http.invalid_json', [
    'route' => '/api/v1/ping',
    'methods' => ['POST'],
    'rbac' => 'public',
    'responses' => [
        '400' => [
            'error' => 'http.invalid_json',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/ping',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'http.invalid_json',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'Invalid JSON payload.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'http.invalid_json',
                    'message' => 'Invalid JSON payload.',
                ],
                'problem' => [
                    'type' => 'laas:error/http.invalid_json',
                    'title' => 'Invalid JSON.',
                    'status' => 400,
                    'instance' => 'req-1',
                ],
                'route' => '/api/v1/ping',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('http.too_many_fields', [
    'route' => '/api/v1/ping',
    'methods' => ['POST'],
    'rbac' => 'public',
    'responses' => [
        '400' => [
            'error' => 'http.too_many_fields',
            'meta' => [
                'format' => 'json',
                'route' => '/api/v1/ping',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'http.too_many_fields',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_INVALID_REQUEST',
                'message' => 'Too many form fields.',
            ],
            'meta' => [
                'format' => 'json',
                'ok' => false,
                'error' => [
                    'key' => 'http.too_many_fields',
                    'message' => 'Too many form fields.',
                ],
                'problem' => contract_problem('http.too_many_fields', 400, 'Error.'),
                'route' => '/api/v1/ping',
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
            'error' => 'error.rbac_denied',
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
            'error' => 'error.rbac_denied',
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
            'data' => null,
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
                'ok' => false,
                'error' => [
                    'key' => 'error.validation_failed',
                    'message' => 'Validation failed.',
                ],
                'problem' => contract_problem('error.validation_failed', 422, 'Error.'),
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
            'error' => 'error.rbac_denied',
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
                'events' => 'array',
                'route' => 'admin.api_tokens.create',
            ],
        ],
        '422' => [
            'error' => 'validation_failed',
            'fields' => 'object',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.api_tokens.create',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
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
                'events' => [
                    [
                        'type' => 'success',
                        'message_key' => 'admin.api_tokens.created',
                        'message' => 'Token created.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.api_tokens.create',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
    'example_error' => [
        'fixture' => 'admin.api_tokens.create.validation_failed',
        'payload' => [
            'data' => null,
            'error' => [
                'code' => 'E_VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'details' => [
                    'fields' => [
                        'name' => ['invalid'],
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => [
                    [
                        'type' => 'danger',
                        'message_key' => 'toast.validation_failed',
                        'message' => 'Validation failed.',
                        'request_id' => 'req-1',
                    ],
                ],
                'ok' => false,
                'error' => [
                    'key' => 'error.validation_failed',
                    'message' => 'Validation failed.',
                ],
                'problem' => contract_problem('error.validation_failed', 422, 'Error.'),
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
                'events' => 'array',
                'route' => 'admin.api_tokens.revoke',
            ],
        ],
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.api_tokens.revoke',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
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
                'events' => [
                    [
                        'type' => 'info',
                        'message_key' => 'admin.api_tokens.revoked',
                        'message' => 'Token revoked.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.api_tokens.revoke',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.security_reports.index', [
    'route' => '/admin/security-reports',
    'methods' => ['GET'],
    'rbac' => 'security_reports.view',
    'responses' => [
        '200' => [
            'data' => [
                'items' => [
                    [
                        'id' => 'int',
                        'type' => 'string',
                        'status' => 'string',
                        'document_uri' => 'string',
                        'violated_directive' => 'string',
                        'blocked_uri' => 'string',
                        'user_agent' => 'string',
                        'ip' => 'string',
                        'request_id' => 'string|null',
                        'created_at' => 'string',
                        'updated_at' => 'string',
                        'triaged_at' => 'string|null',
                        'ignored_at' => 'string|null',
                        'severity' => 'string',
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
                'route' => 'admin.security_reports.index',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.security_reports.index',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.security_reports.index',
        'payload' => [
            'data' => [
                'items' => [
                    [
                        'id' => 1,
                        'type' => 'csp',
                        'status' => 'new',
                        'document_uri' => 'https://example.com',
                        'violated_directive' => 'script-src',
                        'blocked_uri' => 'https://evil.example/script.js',
                        'user_agent' => 'Mozilla/5.0',
                        'ip' => '203.0.113.10',
                        'request_id' => 'req-1',
                        'created_at' => '2026-01-01 00:00:00',
                        'updated_at' => '2026-01-01 00:00:00',
                        'triaged_at' => null,
                        'ignored_at' => null,
                        'severity' => 'high',
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
                'route' => 'admin.security_reports.index',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.security_reports.show', [
    'route' => '/admin/security-reports/{id}',
    'methods' => ['GET'],
    'rbac' => 'security_reports.view',
    'responses' => [
        '200' => [
            'data' => [
                'report' => [
                    'id' => 'int',
                    'type' => 'string',
                    'status' => 'string',
                    'document_uri' => 'string',
                    'violated_directive' => 'string',
                    'blocked_uri' => 'string',
                    'user_agent' => 'string',
                    'ip' => 'string',
                    'request_id' => 'string|null',
                    'created_at' => 'string',
                    'updated_at' => 'string',
                    'triaged_at' => 'string|null',
                    'ignored_at' => 'string|null',
                    'severity' => 'string',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.security_reports.show',
            ],
        ],
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.security_reports.show',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'route' => 'admin.security_reports.show',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.security_reports.show',
        'payload' => [
            'data' => [
                'report' => [
                    'id' => 1,
                    'type' => 'csp',
                    'status' => 'triaged',
                    'document_uri' => 'https://example.com',
                    'violated_directive' => 'script-src',
                    'blocked_uri' => 'https://evil.example/script.js',
                    'user_agent' => 'Mozilla/5.0',
                    'ip' => '203.0.113.10',
                    'request_id' => 'req-1',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-02 00:00:00',
                    'triaged_at' => '2026-01-02 00:00:00',
                    'ignored_at' => null,
                    'severity' => 'high',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'route' => 'admin.security_reports.show',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.security_reports.triage', [
    'route' => '/admin/security-reports/{id}/triage',
    'methods' => ['POST'],
    'rbac' => 'security_reports.manage',
    'responses' => [
        '200' => [
            'data' => [
                'report' => [
                    'id' => 'int',
                    'type' => 'string',
                    'status' => 'string',
                    'document_uri' => 'string',
                    'violated_directive' => 'string',
                    'blocked_uri' => 'string',
                    'user_agent' => 'string',
                    'ip' => 'string',
                    'request_id' => 'string|null',
                    'created_at' => 'string',
                    'updated_at' => 'string',
                    'triaged_at' => 'string|null',
                    'ignored_at' => 'string|null',
                    'severity' => 'string',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.triage',
            ],
        ],
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.triage',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.triage',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.security_reports.triage',
        'payload' => [
            'data' => [
                'report' => [
                    'id' => 1,
                    'type' => 'csp',
                    'status' => 'triaged',
                    'document_uri' => 'https://example.com',
                    'violated_directive' => 'script-src',
                    'blocked_uri' => 'https://evil.example/script.js',
                    'user_agent' => 'Mozilla/5.0',
                    'ip' => '203.0.113.10',
                    'request_id' => 'req-1',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-02 00:00:00',
                    'triaged_at' => '2026-01-02 00:00:00',
                    'ignored_at' => null,
                    'severity' => 'high',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => [
                    [
                        'type' => 'info',
                        'message_key' => 'admin.security_reports.updated',
                        'message' => 'Security report updated.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.security_reports.triage',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.security_reports.ignore', [
    'route' => '/admin/security-reports/{id}/ignore',
    'methods' => ['POST'],
    'rbac' => 'security_reports.manage',
    'responses' => [
        '200' => [
            'data' => [
                'report' => [
                    'id' => 'int',
                    'type' => 'string',
                    'status' => 'string',
                    'document_uri' => 'string',
                    'violated_directive' => 'string',
                    'blocked_uri' => 'string',
                    'user_agent' => 'string',
                    'ip' => 'string',
                    'request_id' => 'string|null',
                    'created_at' => 'string',
                    'updated_at' => 'string',
                    'triaged_at' => 'string|null',
                    'ignored_at' => 'string|null',
                    'severity' => 'string',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.ignore',
            ],
        ],
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.ignore',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.ignore',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.security_reports.ignore',
        'payload' => [
            'data' => [
                'report' => [
                    'id' => 1,
                    'type' => 'csp',
                    'status' => 'ignored',
                    'document_uri' => 'https://example.com',
                    'violated_directive' => 'script-src',
                    'blocked_uri' => 'https://evil.example/script.js',
                    'user_agent' => 'Mozilla/5.0',
                    'ip' => '203.0.113.10',
                    'request_id' => 'req-1',
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-03 00:00:00',
                    'triaged_at' => null,
                    'ignored_at' => '2026-01-03 00:00:00',
                    'severity' => 'high',
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => [
                    [
                        'type' => 'info',
                        'message_key' => 'admin.security_reports.updated',
                        'message' => 'Security report updated.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.security_reports.ignore',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.security_reports.delete', [
    'route' => '/admin/security-reports/{id}/delete',
    'methods' => ['POST'],
    'rbac' => 'security_reports.manage',
    'responses' => [
        '200' => [
            'data' => [
                'deleted' => 'bool',
                'id' => 'int',
            ],
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.delete',
            ],
        ],
        '404' => [
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.delete',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.security_reports.delete',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.security_reports.delete',
        'payload' => [
            'data' => [
                'deleted' => true,
                'id' => 1,
            ],
            'meta' => [
                'format' => 'json',
                'events' => [
                    [
                        'type' => 'info',
                        'message_key' => 'admin.security_reports.deleted',
                        'message' => 'Security report deleted.',
                        'request_id' => 'req-1',
                    ],
                ],
                'route' => 'admin.security_reports.delete',
                'request_id' => 'req-1',
                'ts' => '2026-01-01T00:00:00Z',
            ],
        ],
    ],
]);

ContractRegistry::register('admin.ops.index', [
    'route' => '/admin/ops',
    'methods' => ['GET'],
    'rbac' => 'ops.view',
    'responses' => [
        '200' => [
            'data' => [
                'health' => [
                    'status' => 'string',
                    'checks' => [
                        'db' => 'string',
                        'storage' => 'string',
                        'fs' => 'string',
                        'security_headers' => 'string',
                        'session' => 'string',
                        'backup' => 'string',
                    ],
                    'warnings' => ['string'],
                    'updated_at' => 'string',
                ],
                'sessions' => [
                    'driver' => 'string',
                    'status' => 'string',
                    'failover_active' => 'bool',
                    'details' => ['string'],
                ],
                'backups' => [
                    'writable' => 'string',
                    'writable_details' => ['string'],
                    'last_backup' => [
                        'name' => 'string|null',
                        'created_at' => 'string|null',
                    ],
                    'retention' => [
                        'keep' => 'int',
                        'policy' => 'string',
                    ],
                    'verify_supported' => 'bool',
                ],
                'performance' => [
                    'guard_mode' => 'string',
                    'budgets' => [
                        'total_ms_warn' => 'int',
                        'total_ms_hard' => 'int',
                        'sql_count_warn' => 'int',
                        'sql_count_hard' => 'int',
                        'sql_ms_warn' => 'int',
                        'sql_ms_hard' => 'int',
                    ],
                    'guard_limits' => [
                        'db_max_queries' => 'int',
                        'db_max_unique' => 'int',
                        'db_max_total_ms' => 'int',
                        'http_max_calls' => 'int',
                        'http_max_total_ms' => 'int',
                        'total_max_ms' => 'int',
                    ],
                    'admin_override' => [
                        'enabled' => 'bool',
                    ],
                ],
                'cache' => [
                    'enabled' => 'bool',
                    'driver' => 'string',
                    'default_ttl' => 'int',
                    'tag_ttl' => 'int',
                    'ttl_days' => 'int',
                    'last_prune' => 'string|null',
                ],
                'security' => [
                    'headers_status' => 'string',
                    'headers_details' => ['string'],
                    'reports' => [
                        'last_24h' => 'int|null',
                        'total' => 'int|null',
                    ],
                ],
                'preflight' => [
                    'commands' => ['string'],
                    'env' => [
                        'app_env' => 'string',
                        'app_debug' => 'bool',
                        'read_only' => 'bool',
                        'headless' => 'bool',
                        'storage_disk' => 'string',
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.ops.index',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
            'meta' => [
                'format' => 'json',
                'events' => 'array',
                'route' => 'admin.ops.index',
            ],
        ],
    ],
    'example_ok' => [
        'fixture' => 'admin.ops.index',
        'payload' => [
            'data' => [
                'health' => [
                    'status' => 'ok',
                    'checks' => [
                        'db' => 'ok',
                        'storage' => 'ok',
                        'fs' => 'ok',
                        'security_headers' => 'ok',
                        'session' => 'ok',
                        'backup' => 'ok',
                    ],
                    'warnings' => [],
                    'updated_at' => '2026-01-01T00:00:00Z',
                ],
                'sessions' => [
                    'driver' => 'redis',
                    'status' => 'ok',
                    'failover_active' => false,
                    'details' => [
                        'session storage: OK',
                        'redis session: OK (127.0.0.1:6379/0)',
                    ],
                ],
                'backups' => [
                    'writable' => 'ok',
                    'writable_details' => [
                        'backups dir: OK',
                        'tmp dir: OK',
                    ],
                    'last_backup' => [
                        'name' => 'laas_backup_20260101_000000_v2.tar.gz',
                        'created_at' => '2026-01-01 00:00:00',
                    ],
                    'retention' => [
                        'keep' => 10,
                        'policy' => 'manual',
                    ],
                    'verify_supported' => true,
                ],
                'performance' => [
                    'guard_mode' => 'warn',
                    'budgets' => [
                        'total_ms_warn' => 400,
                        'total_ms_hard' => 1200,
                        'sql_count_warn' => 40,
                        'sql_count_hard' => 120,
                        'sql_ms_warn' => 150,
                        'sql_ms_hard' => 600,
                    ],
                    'guard_limits' => [
                        'db_max_queries' => 80,
                        'db_max_unique' => 60,
                        'db_max_total_ms' => 250,
                        'http_max_calls' => 10,
                        'http_max_total_ms' => 500,
                        'total_max_ms' => 1200,
                    ],
                    'admin_override' => [
                        'enabled' => true,
                    ],
                ],
                'cache' => [
                    'enabled' => true,
                    'driver' => 'file',
                    'default_ttl' => 60,
                    'tag_ttl' => 60,
                    'ttl_days' => 7,
                    'last_prune' => '2026-01-01T00:00:00Z',
                ],
                'security' => [
                    'headers_status' => 'ok',
                    'headers_details' => [
                        'security headers: OK',
                    ],
                    'reports' => [
                        'last_24h' => 3,
                        'total' => 12,
                    ],
                ],
                'preflight' => [
                    'commands' => [
                        'php tools/cli.php preflight',
                        'php tools/cli.php doctor',
                        'php tools/cli.php ops:check',
                    ],
                    'env' => [
                        'app_env' => 'prod',
                        'app_debug' => false,
                        'read_only' => false,
                        'headless' => false,
                        'storage_disk' => 'local',
                    ],
                ],
            ],
            'meta' => [
                'format' => 'json',
                'events' => [],
                'route' => 'admin.ops.index',
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
            'error' => 'error.rbac_denied',
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
            'error' => 'error.rbac_denied',
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
            'error' => 'error.rbac_denied',
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
            'error' => 'error.rbac_denied',
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
            'error' => 'error.not_found',
            'meta' => [
                'format' => 'json',
                'route' => 'media.show',
            ],
        ],
        '403' => [
            'error' => 'error.rbac_denied',
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
