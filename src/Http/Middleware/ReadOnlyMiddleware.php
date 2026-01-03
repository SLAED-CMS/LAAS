<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;

final class ReadOnlyMiddleware implements MiddlewareInterface
{
    private bool $enabled;
    private Translator $translator;

    public function __construct(bool $enabled, Translator $translator)
    {
        $this->enabled = $enabled;
        $this->translator = $translator;
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
        if ($request->wantsJson() || $request->isHtmx()) {
            return Response::json(['error' => $message], 503);
        }

        return new Response($message, 503, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function isAllowed(string $path): bool
    {
        if ($path === '/login' || $path === '/logout' || $path === '/health') {
            return true;
        }

        return false;
    }
}
