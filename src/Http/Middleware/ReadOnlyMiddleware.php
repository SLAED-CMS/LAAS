<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use Laas\View\View;

final class ReadOnlyMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private Translator $translator;
    private ?View $view;

    public function __construct(bool $enabled, Translator $translator, ?View $view = null)
    {
        $this->enabled = $enabled;
        $this->translator = $translator;
        $this->view = $view;
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->enabled) {
            return $next($request);
        }

        $method = $request->getMethod();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $path = $request->getPath();
        if ($this->isAllowed($path)) {
            return $next($request);
        }

        $message = $this->translator->trans('system.read_only');
        if (str_starts_with($path, '/api/')) {
            return ErrorResponse::respond($request, ErrorCode::READ_ONLY, [], 503, [], 'read_only.middleware');
        }

        if ($request->isHtmx() && $this->view !== null) {
            $theme = str_starts_with($path, '/admin') ? 'admin' : null;
            return $this->view->render('partials/messages.html', [
                'errors' => [$message],
            ], 503, [], $theme !== null ? ['theme' => $theme] : []);
        }

        if ($request->wantsJson()) {
            return ErrorResponse::respond($request, ErrorCode::READ_ONLY, [], 503, [], 'read_only.middleware');
        }

        return new Response($message, 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function isAllowed(string $path): bool
    {
        if ($path === '/login' || $path === '/logout' || $path === '/csrf' || $path === '/health' || $path === '/api/v1/ping') {
            return true;
        }

        return false;
    }
}
