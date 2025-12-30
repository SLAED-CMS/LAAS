<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Auth\AuthInterface;
use Laas\Http\Request;
use Laas\Http\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthInterface $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (str_starts_with($request->getPath(), '/admin')) {
            if (!$this->auth->check()) {
                return (new Response('', 302, [
                    'Location' => '/login',
                ]));
            }
        }

        return $next($request);
    }
}
