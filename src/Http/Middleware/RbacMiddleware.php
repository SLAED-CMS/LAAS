<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;

final class RbacMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthInterface $auth,
        private AuthorizationService $authorization
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!str_starts_with($request->getPath(), '/admin')) {
            return $next($request);
        }

        if (!$this->auth->check()) {
            if ($request->wantsJson()) {
                return ErrorResponse::respond($request, ErrorCode::AUTH_REQUIRED, [], 401, [], 'rbac.guard');
            }
            return new Response('', 302, [
                'Location' => '/login',
            ]);
        }

        $user = $this->auth->user();
        if (!$this->authorization->can($user, 'admin.access')) {
            if ($request->wantsJson()) {
                return ErrorResponse::respond($request, ErrorCode::RBAC_DENIED, [], 403, [], 'rbac.guard');
            }
            return ErrorResponse::respondForRequest($request, ErrorCode::RBAC_DENIED, [], 403, [], 'rbac.guard');
        }

        return $next($request);
    }
}
