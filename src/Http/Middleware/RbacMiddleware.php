<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Auth\AuthInterface;
use Laas\Auth\AuthorizationService;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class RbacMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthInterface $auth,
        private AuthorizationService $authorization,
        private View $view
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!str_starts_with($request->getPath(), '/admin')) {
            return $next($request);
        }

        if (!$this->auth->check()) {
            return new Response('', 302, [
                'Location' => '/login',
            ]);
        }

        $user = $this->auth->user();
        if (!$this->authorization->can($user, 'admin.access')) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'forbidden'], 403);
            }

            return $this->view->render('pages/403.html', [], 403, [], [
                'theme' => 'admin',
            ]);
        }

        return $next($request);
    }
}
