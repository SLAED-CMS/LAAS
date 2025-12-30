<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\SecurityHeaders;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private SecurityHeaders $securityHeaders)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach ($this->securityHeaders->all() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
