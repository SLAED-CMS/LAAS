<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;

final class MiddlewareQueue
{
    /** @var MiddlewareInterface[] */
    private array $queue;

    /** @param MiddlewareInterface[] $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function dispatch(Request $request, callable $lastHandler): Response
    {
        $handler = $lastHandler;

        for ($i = count($this->queue) - 1; $i >= 0; $i--) {
            $middleware = $this->queue[$i];
            $next = $handler;

            $handler = static function (Request $request) use ($middleware, $next): Response {
                return $middleware->process($request, $next);
            };
        }

        return $handler($request);
    }
}
