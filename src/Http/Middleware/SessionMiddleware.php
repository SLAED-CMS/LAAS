<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Http\Session\SessionManager;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionManager $sessionManager)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->sessionManager->start();

        return $next($request);
    }
}
