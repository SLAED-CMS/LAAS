<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\DevTools\DevToolsContext;
use Laas\Support\RequestScope;

final class RequestContext
{
    public static function requestId(): ?string
    {
        $fromScope = RequestScope::get('request.id');
        if (is_string($fromScope)) {
            $fromScope = trim($fromScope);
            if ($fromScope !== '') {
                return $fromScope;
            }
        }

        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $rid = $context->getRequestId();
            if (is_string($rid)) {
                $rid = trim($rid);
                if ($rid !== '') {
                    return $rid;
                }
            }
        }

        $request = RequestScope::getRequest();
        if ($request instanceof Request) {
            $rid = $request->getHeader('x-request-id');
            if (is_string($rid)) {
                $rid = trim($rid);
                if ($rid !== '') {
                    return $rid;
                }
            }
        }

        $rid = $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['X_REQUEST_ID'] ?? null;
        if (!is_string($rid)) {
            $generated = bin2hex(random_bytes(16));
            RequestScope::set('request.id', $generated);
            return $generated;
        }
        $rid = trim($rid);
        if ($rid === '') {
            $generated = bin2hex(random_bytes(16));
            RequestScope::set('request.id', $generated);
            return $generated;
        }
        RequestScope::set('request.id', $rid);
        return $rid;
    }

    public static function path(): ?string
    {
        $request = RequestScope::getRequest();
        if ($request instanceof Request) {
            $path = trim((string) $request->getPath());
            return $path !== '' ? $path : null;
        }

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

    public static function timestamp(): string
    {
        return gmdate('c');
    }
}
