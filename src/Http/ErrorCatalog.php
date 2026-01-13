<?php
declare(strict_types=1);

namespace Laas\Http;

final class ErrorCatalog
{
    private const MAP = [
        ErrorCode::AUTH_REQUIRED => ['status' => 401, 'message_key' => 'error.auth_required'],
        ErrorCode::AUTH_INVALID => ['status' => 401, 'message_key' => 'error.auth_invalid'],
        ErrorCode::RBAC_DENIED => ['status' => 403, 'message_key' => 'error.rbac_denied'],
        ErrorCode::CSRF_INVALID => ['status' => 419, 'message_key' => 'error.csrf_invalid'],
        ErrorCode::RATE_LIMITED => ['status' => 429, 'message_key' => 'rate_limit.exceeded'],
        ErrorCode::VALIDATION_FAILED => ['status' => 422, 'message_key' => 'error.validation_failed'],
        ErrorCode::NOT_FOUND => ['status' => 404, 'message_key' => 'error.not_found'],
        ErrorCode::METHOD_NOT_ALLOWED => ['status' => 405, 'message_key' => 'error.method_not_allowed'],
        ErrorCode::INTERNAL => ['status' => 500, 'message_key' => 'error.internal'],
        ErrorCode::READ_ONLY => ['status' => 503, 'message_key' => 'system.read_only'],
        ErrorCode::FORMAT_NOT_ACCEPTABLE => ['status' => 406, 'message_key' => 'system.not_acceptable'],
        ErrorCode::MEDIA_FORBIDDEN => ['status' => 403, 'message_key' => 'error.media_forbidden'],
        ErrorCode::API_TOKEN_INVALID => ['status' => 401, 'message_key' => 'auth.invalid_token'],
        ErrorCode::BACKUP_VERIFY_FAILED => ['status' => 500, 'message_key' => 'error.backup_verify_failed'],
        ErrorCode::INVALID_REQUEST => ['status' => 400, 'message_key' => 'error.invalid_request'],
        ErrorCode::SERVICE_UNAVAILABLE => ['status' => 503, 'message_key' => 'error.service_unavailable'],
    ];

    private const ALIASES = [
        'auth.invalid_token' => ['code' => ErrorCode::API_TOKEN_INVALID, 'message_key' => 'auth.invalid_token', 'status' => 401],
        'auth.token_expired' => ['code' => ErrorCode::API_TOKEN_INVALID, 'message_key' => 'auth.token_expired', 'status' => 401],
        'csrf_mismatch' => ErrorCode::CSRF_INVALID,
        'not_found' => ErrorCode::NOT_FOUND,
        'method_not_allowed' => ErrorCode::METHOD_NOT_ALLOWED,
        'forbidden' => ErrorCode::RBAC_DENIED,
        'unauthorized' => ErrorCode::AUTH_REQUIRED,
        'rate_limited' => ErrorCode::RATE_LIMITED,
        'rate limit exceeded' => ErrorCode::RATE_LIMITED,
        'read_only' => ErrorCode::READ_ONLY,
        'not_acceptable' => ErrorCode::FORMAT_NOT_ACCEPTABLE,
        'validation_failed' => ErrorCode::VALIDATION_FAILED,
        'error' => ErrorCode::INTERNAL,
        'system.over_budget' => ['code' => ErrorCode::SERVICE_UNAVAILABLE, 'message_key' => 'system.over_budget', 'status' => 503],
        'service_unavailable' => ErrorCode::SERVICE_UNAVAILABLE,
        'db_unavailable' => ErrorCode::SERVICE_UNAVAILABLE,
        'storage_error' => ['code' => ErrorCode::INTERNAL, 'message_key' => 'error.storage_error', 'status' => 500],
        'invalid_request' => ErrorCode::INVALID_REQUEST,
        'protected_module' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.protected_module', 'status' => 400],
        'file_too_large' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.file_too_large', 'status' => 413],
        'invalid_mime' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.invalid_mime', 'status' => 400],
        'signed_url' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.signed_url_invalid', 'status' => 400],
        'content-type must be application/json' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.invalid_content_type', 'status' => 400],
        'empty body' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.empty_body', 'status' => 400],
        'invalid json' => ['code' => ErrorCode::INVALID_REQUEST, 'message_key' => 'error.invalid_json', 'status' => 400],
        'type and message are required' => ['code' => ErrorCode::VALIDATION_FAILED, 'message_key' => 'error.validation_failed', 'status' => 422],
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
