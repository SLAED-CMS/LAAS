<?php
declare(strict_types=1);

namespace Laas\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\RequestScope;

use function FastRoute\simpleDispatcher;

final class Router
{
    private array $routes = [];

    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [$method, $path, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$method, $path, $handler]) {
                $r->addRoute($method, $path, $handler);
            }
        });

        $routeInfo = $dispatcher->dispatch(strtoupper($request->getMethod()), $request->getPath());

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                $headers = [];
                $devtoolsPaths = RequestScope::get('devtools.paths');
                if (is_array($devtoolsPaths) && in_array($request->getPath(), $devtoolsPaths, true)) {
                    $headers['Cache-Control'] = 'no-store';
                }
                return ErrorResponse::respondForRequest($request, ErrorCode::NOT_FOUND, [], 404, [], 'router', $headers);
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed = $routeInfo[1] ?? [];
                $allowHeader = is_array($allowed) ? implode(', ', $allowed) : '';
                $headers = $allowHeader !== '' ? ['Allow' => $allowHeader] : [];
                return ErrorResponse::respondForRequest($request, ErrorCode::METHOD_NOT_ALLOWED, [], 405, [], 'router', $headers);
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                $routePattern = $this->findRoutePattern($request, $handler);
                if ($routePattern !== null) {
                    $request->setAttribute('route.pattern', $routePattern);
                }
                $request->setAttribute('route.handler', $handler);
                $request->setAttribute('route.vars', $vars);

                $response = $handler($request, $vars);
                if ($response instanceof Response) {
                    return $response;
                }

                return new Response('', 204);
        }

        return new Response('Unhandled Router State', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function findRoutePattern(Request $request, callable $handler): ?string
    {
        $method = strtoupper($request->getMethod());
        foreach ($this->routes as [$routeMethod, $path, $routeHandler]) {
            if (strtoupper((string) $routeMethod) !== $method) {
                continue;
            }
            if ($routeHandler === $handler) {
                return is_string($path) ? $path : null;
            }
        }

        return null;
    }
}
