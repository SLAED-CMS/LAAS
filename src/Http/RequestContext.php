<?php
declare(strict_types=1);

namespace Laas\Http;

final class RequestContext
{
    public static function requestId(): ?string
    {
        $rid = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['X_REQUEST_ID'] ?? null;
        if (!is_string($rid)) {
            return null;
        }
        $rid = trim($rid);
        return $rid !== '' ? $rid : null;
    }

    public static function path(): ?string
    {
        $raw = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $pos = strpos($raw, '?');
        if ($pos === false) {
            return $raw;
        }
        $path = substr($raw, 0, $pos);
        return $path !== '' ? $path : null;
    }

    public static function isDebug(): bool
    {
        $value = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? null;
        if ($value === null || $value === '') {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? false;
    }
}
