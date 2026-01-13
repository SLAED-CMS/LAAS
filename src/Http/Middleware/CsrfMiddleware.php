<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\Csrf;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function process(Request $request, callable $next): Response
    {
        if (str_starts_with($request->getPath(), '/api/')) {
            return $next($request);
        }

        if ($request->getPath() === '/__csp/report') {
            return $next($request);
        }

        if (!in_array($request->getMethod(), self::METHODS, true)) {
            return $next($request);
        }

        $csrf = new Csrf($request->session());
        $token = $request->post(Csrf::FORM_KEY);
        if ($token === null || $token === '') {
            $token = $request->header(Csrf::HEADER_KEY);
        }

        if (!$csrf->validate($token)) {
            if ($request->wantsJson()) {
                return ErrorResponse::respond($request, ErrorCode::CSRF_INVALID, [], 419, [], 'csrf.middleware');
            }

            return new Response('419 CSRF Token Mismatch', 419, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return $next($request);
    }
}
