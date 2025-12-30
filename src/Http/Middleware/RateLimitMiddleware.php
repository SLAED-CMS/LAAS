<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private array $config
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $path = $request->getPath();

        if (str_starts_with($path, '/api/')) {
            $apiConfig = $this->config['rate_limit']['api'] ?? ['window' => 60, 'max' => 60];
            $window = (int) ($apiConfig['window'] ?? 60);
            $max = (int) ($apiConfig['max'] ?? 60);

            $result = $this->rateLimiter->hit('api', $request->ip(), $window, $max);

            if (!$result['allowed']) {
                return Response::json(['error' => 'rate_limited'], 429)
                    ->withHeader('Retry-After', (string) $result['retry_after']);
            }

            $response = $next($request);
            $response = $response
                ->withHeader('X-RateLimit-Limit', (string) $max)
                ->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
                ->withHeader('X-RateLimit-Reset', (string) $result['reset']);

            return $response;
        }

        if ($path === '/login' || $path === '/admin/login') {
            $loginConfig = $this->config['rate_limit']['login'] ?? ['window' => 60, 'max' => 10];
            $window = (int) ($loginConfig['window'] ?? 60);
            $max = (int) ($loginConfig['max'] ?? 10);

            $result = $this->rateLimiter->hit('login', $request->ip(), $window, $max);
            if (!$result['allowed']) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'rate_limited'], 429)
                        ->withHeader('Retry-After', (string) $result['retry_after']);
                }

                return new Response('Too Many Requests', 429, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ])->withHeader('Retry-After', (string) $result['retry_after']);
            }
        }

        return $next($request);
    }
}
