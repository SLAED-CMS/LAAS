<?php
declare(strict_types=1);

namespace Laas\Http;

use Laas\DevTools\DevToolsContext;
use Laas\Support\RequestScope;

final class RequestContext
{
    public static function requestId(): string
    {
        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext) {
            $id = $context->getRequestId();
            if ($id !== '') {
                return $id;
            }
        }

        $cached = RequestScope::get('request.id');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $id = bin2hex(random_bytes(8));
        RequestScope::set('request.id', $id);

        return $id;
    }

    public static function timestamp(): string
    {
        return gmdate('Y-m-d\\TH:i:s\\Z');
    }
}
