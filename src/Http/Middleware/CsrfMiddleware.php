<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\HeadlessMode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\Csrf;
use Laas\Support\RequestScope;
use Laas\View\View;

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
            if ($this->shouldReturnJson($request)) {
                return ErrorResponse::respond($request, 'security.csrf_failed', [], 403, [
                    'route' => HeadlessMode::resolveRoute($request),
                ], 'csrf.middleware');
            }

            $payload = ErrorResponse::buildPayload($request, ErrorCode::CSRF_INVALID, [], 403, [
                'route' => HeadlessMode::resolveRoute($request),
            ], 'csrf.middleware');
            $message = (string) (($payload['payload']['meta']['error']['message'] ?? '') ?: 'Forbidden');

            return $this->renderHtmlError($request, 403, $message);
        }

        return $next($request);
    }

    private function shouldReturnJson(Request $request): bool
    {
        return $request->isHeadless() || $request->wantsJson() || $request->acceptsJson();
    }

    private function renderHtmlError(Request $request, int $status, string $message): Response
    {
        $view = RequestScope::get('view');
        if (!$view instanceof View) {
            return new Response($message, $status, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $template = 'pages/' . $status . '.html';
        $theme = str_starts_with($request->getPath(), '/admin') ? 'admin' : null;
        $options = $theme !== null ? ['theme' => $theme] : [];

        try {
            return $view->render($template, [
                'message' => $message,
            ], $status, [], $options);
        } catch (\Throwable) {
            return new Response($message, $status, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }
    }
}
