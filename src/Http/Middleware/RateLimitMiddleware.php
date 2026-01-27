<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\RateLimiter;

final class RateLimitMiddleware implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private array $profileConfig;

    public function __construct(
        private RateLimiter $rateLimiter,
        private array $config
    ) {
        $this->profileConfig = $this->loadProfileConfig();
    }

    public function process(Request $request, callable $next): Response
    {
        $rateLimit = $this->config['rate_limit'] ?? [];
        $enabled = $rateLimit['enabled'] ?? true;
        if (!$enabled) {
            return $next($request);
        }

        $path = $request->getPath();

        if (str_starts_with($path, '/api/')) {
            $apiProfile = $this->resolveProfile('api_default', 'api');
            if ($apiProfile === null) {
                return $next($request);
            }
            $window = (int) ($apiProfile['window'] ?? 60);
            $max = (int) ($apiProfile['max'] ?? 120);
            $burst = isset($apiProfile['burst']) ? (int) $apiProfile['burst'] : 30;

            $token = $request->getAttribute('api.token');
            $key = is_array($token) && !empty($token['token_hash'])
                ? (string) $token['token_hash']
                : $request->ip();

            $result = $this->rateLimiter->hit('api_default', $key, $window, $max, $burst);

            if (!$result['allowed']) {
                return ErrorResponse::respond(
                    $request,
                    ErrorCode::RATE_LIMITED,
                    ['retry_after' => (int) $result['retry_after']],
                    429,
                    [],
                    'rate_limit.middleware',
                    ['Retry-After' => (string) $result['retry_after']]
                );
            }

            $response = $next($request);
            $response = $response
                ->withHeader('X-RateLimit-Limit', (string) $max)
                ->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
                ->withHeader('X-RateLimit-Reset', (string) $result['reset']);

            return $response;
        }

        if ($this->profileConfig === [] && ($path === '/login' || $path === '/admin/login')) {
            $loginConfig = $this->config['rate_limit']['login'] ?? ['window' => 60, 'max' => 10];
            $window = (int) ($loginConfig['window'] ?? 60);
            $max = (int) ($loginConfig['max'] ?? 10);

            $result = $this->rateLimiter->hit('login', $request->ip(), $window, $max);
            if (!$result['allowed']) {
                return $this->rateLimitedResponse($request, $result['retry_after']);
            }
        }

        $profileName = $this->resolveProfileName($request);
        if ($profileName !== null) {
            $profile = $this->resolveProfile($profileName, $profileName);
            if ($profile !== null) {
                $window = (int) ($profile['window'] ?? 60);
                $max = (int) ($profile['max'] ?? 60);
                $burst = isset($profile['burst']) ? (int) $profile['burst'] : null;

                $result = $this->rateLimiter->hit($profileName, $request->ip(), $window, $max, $burst);
                if (!$result['allowed']) {
                    return $this->rateLimitedResponse($request, $result['retry_after']);
                }
            }
        }

        return $next($request);
    }

    private function loadProfileConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/rate_limits.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function resolveProfileName(Request $request): ?string
    {
        $routeName = $request->getAttribute('route.name');
        $routeMap = $this->profileConfig['route_names'] ?? [];
        if (is_string($routeName) && $routeName !== '' && is_array($routeMap)) {
            $override = $routeMap[$routeName] ?? null;
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        $rules = $this->profileConfig['routes'] ?? [];
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                if ($this->matchRule($request, $rule)) {
                    $profile = $rule['profile'] ?? null;
                    if (is_string($profile) && $profile !== '') {
                        return $profile;
                    }
                }
            }
        }

        $method = strtoupper($request->getMethod());
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return null;
        }

        $fallback = $this->profileConfig['fallback'] ?? null;
        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    private function matchRule(Request $request, array $rule): bool
    {
        $methods = $rule['methods'] ?? null;
        if ($methods !== null) {
            $allowed = $this->normalizeMethods($methods);
            if ($allowed !== [] && !in_array(strtoupper($request->getMethod()), $allowed, true)) {
                return false;
            }
        }

        $path = $rule['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $match = (string) ($rule['match'] ?? '');
            if ($match === 'prefix' || str_ends_with($path, '*')) {
                $prefix = rtrim($path, '*');
                return $prefix === '' ? false : str_starts_with($request->getPath(), $prefix);
            }
            return $request->getPath() === $path;
        }

        $pattern = $rule['route_pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $current = $request->getAttribute('route.pattern');
            return is_string($current) && $current === $pattern;
        }

        return false;
    }

    private function normalizeMethods(mixed $methods): array
    {
        if (is_string($methods)) {
            $methods = [$methods];
        }
        if (!is_array($methods)) {
            return [];
        }
        $out = [];
        foreach ($methods as $method) {
            if (!is_string($method)) {
                continue;
            }
            $method = strtoupper(trim($method));
            if ($method !== '') {
                $out[] = $method;
            }
        }
        return array_values(array_unique($out));
    }

    private function resolveProfile(string $profileName, string $legacyKey): ?array
    {
        $profiles = $this->profileConfig['profiles'] ?? [];
        if (is_array($profiles) && array_key_exists($profileName, $profiles)) {
            $profile = $profiles[$profileName];
            return is_array($profile) ? $this->normalizeProfile($profile) : null;
        }

        $legacy = $this->config['rate_limit'][$legacyKey] ?? null;
        if (is_array($legacy)) {
            return $this->normalizeProfile($legacy);
        }

        return null;
    }

    private function normalizeProfile(array $profile): array
    {
        $window = (int) ($profile['window'] ?? 60);
        $max = $profile['max'] ?? ($profile['per_minute'] ?? 60);
        $burst = $profile['burst'] ?? null;

        return [
            'window' => $window > 0 ? $window : 60,
            'max' => is_numeric($max) ? (int) $max : 60,
            'burst' => is_numeric($burst) ? (int) $burst : null,
        ];
    }

    private function rateLimitedResponse(Request $request, int $retryAfter): Response
    {
        return ErrorResponse::respondForRequest(
            $request,
            ErrorCode::RATE_LIMITED,
            ['retry_after' => $retryAfter],
            429,
            [],
            'rate_limit.middleware',
            ['Retry-After' => (string) $retryAfter]
        );
    }
}
