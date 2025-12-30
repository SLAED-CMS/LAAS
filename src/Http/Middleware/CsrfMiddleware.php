<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\Csrf;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private Csrf $csrf)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!in_array($request->getMethod(), self::METHODS, true)) {
            return $next($request);
        }

        $token = $request->post(Csrf::FORM_KEY);
        if ($token === null || $token === '') {
            $token = $request->header(Csrf::HEADER_KEY);
        }

        if (!$this->csrf->validate($token)) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'csrf_mismatch'], 419);
            }

            return new Response('419 CSRF Token Mismatch', 419, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return $next($request);
    }
}
