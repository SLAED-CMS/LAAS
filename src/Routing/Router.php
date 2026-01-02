<?php
declare(strict_types=1);

namespace Laas\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Laas\Http\Request;
use Laas\Http\Response;

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
                return new Response('Not Found', 404, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed = $routeInfo[1] ?? [];
                $allowHeader = is_array($allowed) ? implode(', ', $allowed) : '';
                return new Response(
                    'Method Not Allowed' . ($allowHeader !== '' ? ' (Allow: ' . $allowHeader . ')' : ''),
                    405,
                    array_filter([
                        'Content-Type' => 'text/plain; charset=utf-8',
                        $allowHeader !== '' ? 'Allow' : '' => $allowHeader,
                    ])
                );
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];

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
}
