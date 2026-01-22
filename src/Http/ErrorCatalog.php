<?php

declare(strict_types=1);

namespace Laas\Http;

final class ErrorCatalog
{
    private const MAP = [
        ErrorCode::AUTH_REQUIRED => ['status' => 401, 'message_key' => 'error.auth_required'],
        ErrorCode::AUTH_INVALID => ['status' => 401, 'message_key' => 'error.auth_invalid'],
        ErrorCode::RBAC_DENIED => ['status' => 403, 'message_key' => 'error.rbac_denied'],
        ErrorCode::CSRF_INVALID => ['status' => 403, 'message_key' => 'security.csrf_failed'],
        ErrorCode::RATE_LIMITED => ['status' => 429, 'message_key' => 'rate_limited'],
        ErrorCode::VALIDATION_FAILED => ['status' => 422, 'message_key' => 'error.validation_failed'],
        ErrorCode::NOT_FOUND => ['status' => 404, 'message_key' => 'error.not_found'],
        ErrorCode::METHOD_NOT_ALLOWED => ['status' => 405, 'message_key' => 'error.method_not_allowed'],
        ErrorCode::INTERNAL => ['status' => 500, 'message_key' => 'error.internal'],
        ErrorCode::READ_ONLY => ['status' => 503, 'message_key' => 'system.read_only'],
        ErrorCode::FORMAT_NOT_ACCEPTABLE => ['status' => 406, 'message_key' => 'system.not_acceptable'],
        ErrorCode::MEDIA_FORBIDDEN => ['status' => 403, 'message_key' => 'error.media_forbidden'],
        ErrorCode::API_TOKEN_INVALID => ['status' => 401, 'message_key' => 'auth.invalid_token'],
        ErrorCode::BACKUP_VERIFY_FAILED => ['status' => 500, 'message_key' => 'error.backup_verify_failed'],
        ErrorCode::PERF_BUDGET_EXCEEDED => ['status' => 503, 'message_key' => 'perf.budget_exceeded'],
        ErrorCode::INVALID_REQUEST => ['status' => 400, 'message_key' => 'error.invalid_request'],
        ErrorCode::SERVICE_UNAVAILABLE => ['status' => 503, 'message_key' => 'service_unavailable'],
    ];

    private const ALIASES = [
        'auth.invalid_token' => ['code' => ErrorCode::API_TOKEN_INVALID, 'message_key' => 'auth.invalid_token', 'status' => 401],
        'auth.token_expired' => ['code' => ErrorCode::API_TOKEN_INVALID, 'message_key' => 'auth.token_expired', 'status' => 401],
        'auth.unauthorized' => ['code' => ErrorCode::AUTH_REQUIRED, 'message_key' => 'error.auth_required', 'status' => 401],
        'api.auth.forbidden_scope' => ['code' => ErrorCode::RBAC_DENIED, 'message_key' => 'api.auth.forbidden_scope', 'status' => 403],
        'csrf_mismatch' => ErrorCode::CSRF_INVALID,
        'security.csrf_failed' => ['code' => ErrorCode::CSRF_INVALID, 'message_key' => 'security.csrf_failed', 'status' => 403],
        'rbac.forbidden' => ['code' => ErrorCode::RBAC_DENIED, 'message_key' => 'error.rbac_denied', 'status' => 403],
        'not_found' => ErrorCode::NOT_FOUND,
        'http.not_found' => ['code' => ErrorCode::NOT_FOUND, 'message_key' => 'error.not_found', 'status' => 404],
        'method_not_allowed' => ErrorCode::METHOD_NOT_ALLOWED,
        'forbidden' => ErrorCode::RBAC_DENIED,
        'unauthorized' => ErrorCode::AUTH_REQUIRED,
        'rate_limited' => ErrorCode::RATE_LIMITED,
        'rate limit exceeded' => ErrorCode::RATE_LIMITED,
        'http.rate_limited' => ['code' => ErrorCode::RATE_LIMITED, 'message_key' => 'rate_limited', 'status' => 429],
        'read_only' => ErrorCode::READ_ONLY,
        'not_acceptable' => ErrorCode::FORMAT_NOT_ACCEPTABLE,
        'validation_failed' => ErrorCode::VALIDATION_FAILED,
        'error' => ErrorCode::INTERNAL,
        'system.over_budget' => ['code' => ErrorCode::SERVICE_UNAVAILABLE, 'message_key' => 'system.over_budget', 'status' => 503],
        'service_unavailable' => ErrorCode::SERVICE_UNAVAILABLE,
        'db_unavailable' => ErrorCode::SERVICE_UNAVAILABLE,
        'storage_error' => ['code' => ErrorCode::INTERNAL, 'message_key' => 'error.storage_error', 'status' => 500],
        'invalid_request' => ErrorCode::INVALID_REQUEST,
        'http.bad_request' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.invalid_request', 'status' => 400],
        'protected_module' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.protected_module', 'status' => 400],
        'file_too_large' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.file_too_large', 'status' => 413],
        'invalid_mime' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.invalid_mime', 'status' => 400],
        'signed_url' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.signed_url_invalid', 'status' => 400],
        'content-type must be application/json' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.invalid_content_type', 'status' => 400],
        'empty body' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.empty_body', 'status' => 400],
        'invalid json' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.invalid_json', 'status' => 400],
        'type and message are required' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.validation_failed', 'status' => 422],
        'error.auth_required' => ['code' => ErrorCode::AUTH_REQUIRED, 'message_key' => 'error.auth_required', 'status' => 401],
        'error.auth_invalid' => ['code' => ErrorCode::AUTH_INVALID, 'message_key' => 'error.auth_invalid', 'status' => 401],
        'error.rbac_denied' => ['code' => ErrorCode::RBAC_DENIED, 'message_key' => 'error.rbac_denied', 'status' => 403],
        'error.not_found' => ['code' => ErrorCode::NOT_FOUND, 'message_key' => 'error.not_found', 'status' => 404],
        'error.invalid_request' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.invalid_request', 'status' => 400],
        'error.service_unavailable' => ['code' => ErrorCode::SERVICE_UNAVAILABLE, 'message_key' => 'service_unavailable', 'status' => 503],
        'rate_limit.exceeded' => ['code' => ErrorCode::RATE_LIMITED, 'message_key' => 'rate_limited', 'status' => 429],
        'http.payload_too_large' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.payload_too_large', 'status' => 413],
        'http.uri_too_long' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.uri_too_long', 'status' => 414],
        'http.headers_too_large' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.headers_too_large', 'status' => 431],
        'http.invalid_json' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.invalid_json', 'status' => 400],
        'http.too_many_fields' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'http.too_many_fields', 'status' => 400],
    ];

    /** @return array{code: string, status: int, message_key: string} */
    public static function resolve(string $codeOrAlias): array
    {
        if (isset(self::MAP[$codeOrAlias])) {
            $entry = self::MAP[$codeOrAlias];
            return [
                'code' => $codeOrAlias,
                'status' => (int) ($entry['status'] ?? 500),
                'message_key' => (string) ($entry['message_key'] ?? 'error.internal'),
            ];
        }

        if (isset(self::ALIASES[$codeOrAlias])) {
            $alias = self::ALIASES[$codeOrAlias];
            if (is_string($alias)) {
                $entry = self::MAP[$alias] ?? self::MAP[ErrorCode::INTERNAL];
                return [
                    'code' => $alias,
                    'status' => (int) ($entry['status'] ?? 500),
                    'message_key' => (string) ($entry['message_key'] ?? 'error.internal'),
                ];
            }
            $aliasCode = (string) ($alias['code'] ?? ErrorCode::INTERNAL);
            $entry = self::MAP[$aliasCode] ?? self::MAP[ErrorCode::INTERNAL];
            return [
                'code' => $aliasCode,
                'status' => (int) ($alias['status'] ?? ($entry['status'] ?? 500)),
                'message_key' => (string) ($alias['message_key'] ?? ($entry['message_key'] ?? 'error.internal')),
            ];
        }

        $fallback = self::MAP[ErrorCode::INTERNAL];
        return [
            'code' => ErrorCode::INTERNAL,
            'status' => (int) ($fallback['status'] ?? 500),
            'message_key' => (string) ($fallback['message_key'] ?? 'error.internal'),
        ];
    }
}
