<?php

declare(strict_types=1);

namespace Laas\Http;

final class ErrorCode
{
    public const AUTH_REQUIRED = 'E_AUTH_REQUIRED';
    public const AUTH_INVALID = 'E_AUTH_INVALID';
    public const RBAC_DENIED = 'E_RBAC_DENIED';
    public const CSRF_INVALID = 'E_CSRF_INVALID';
    public const RATE_LIMITED = 'E_RATE_LIMITED';
    public const VALIDATION_FAILED = 'E_VALIDATION_FAILED';
    public const NOT_FOUND = 'E_NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'E_METHOD_NOT_ALLOWED';
    public const INTERNAL = 'E_INTERNAL';
    public const READ_ONLY = 'E_READ_ONLY';
    public const FORMAT_NOT_ACCEPTABLE = 'E_FORMAT_NOT_ACCEPTABLE';
    public const MEDIA_FORBIDDEN = 'E_MEDIA_FORBIDDEN';
    public const API_TOKEN_INVALID = 'E_API_TOKEN_INVALID';
    public const BACKUP_VERIFY_FAILED = 'E_BACKUP_VERIFY_FAILED';
    public const PERF_BUDGET_EXCEEDED = 'E_PERF_BUDGET_EXCEEDED';
    public const INVALID_REQUEST = 'E_INVALID_REQUEST';
    public const SERVICE_UNAVAILABLE = 'E_SERVICE_UNAVAILABLE';
}
