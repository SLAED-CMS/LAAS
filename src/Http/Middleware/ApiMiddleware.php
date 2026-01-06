<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Api\ApiResponse;
use Laas\Api\ApiTokenService;
use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;

final class ApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DatabaseManager $db,
        private AuthorizationService $authorization,
        private array $config
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!str_starts_with($request->getPath(), '/api/')) {
            return $next($request);
        }

        $request->setAttribute('api.request', true);

        if (empty($this->config['enabled'])) {
            return $this->applyHeaders(ApiResponse::error('not_found', 'Not Found', [], 404), $request, null);
        }

        $cors = $this->corsConfig();
        $origin = $this->origin($request);
        $corsHeaders = $this->corsHeaders($origin, $cors);

        if ($this->isCorsPreflight($request)) {
            if ($corsHeaders === null) {
                return $this->applyHeaders(ApiResponse::error('forbidden', 'Forbidden', [], 403), $request, null);
            }

            return $this->applyHeaders(new Response('', 204), $request, $corsHeaders);
        }

        $token = $this->bearerToken($request);
        if ($token !== null) {
            if (!$this->db->healthCheck()) {
                return $this->applyHeaders(ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503), $request, $corsHeaders);
            }

            $auth = (new ApiTokenService($this->db))->authenticate($token);
            if ($auth === null) {
                return $this->applyHeaders($this->unauthorized(), $request, $corsHeaders);
            }

            $request->setAttribute('api.user', $auth['user'] ?? null);
            $request->setAttribute('api.token', $auth['token'] ?? null);
        }

        if ($this->requiresAuth($request)) {
            $user = $request->getAttribute('api.user');
            if (!is_array($user)) {
                return $this->applyHeaders($this->unauthorized(), $request, $corsHeaders);
            }

            if (!$this->authorization->can($user, 'api.access')) {
                return $this->applyHeaders(ApiResponse::error('forbidden', 'Forbidden', [], 403), $request, $corsHeaders);
            }
        }

        $response = $next($request);

        $response = $this->normalizeResponse($request, $response);

        return $this->applyHeaders($response, $request, $corsHeaders);
    }

    private function normalizeResponse(Request $request, Response $response): Response
    {
        $status = $response->getStatus();
        $contentType = strtolower((string) ($response->getHeader('Content-Type') ?? ''));
        $isJson = str_contains($contentType, 'application/json');

        if (!$isJson && ($status === 404 || $status === 405)) {
            $code = $status === 404 ? 'not_found' : 'method_not_allowed';
            $message = $status === 404 ? 'Not Found' : 'Method Not Allowed';
            return ApiResponse::error($code, $message, [], $status);
        }

        if ($status === 200 || $status === 201 || $status === 204) {
            return $response;
        }

        if ($isJson) {
            return $response;
        }

        return ApiResponse::error('error', 'Error', [], $status);
    }

    private function applyHeaders(Response $response, Request $request, ?array $corsHeaders): Response
    {
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        if ($corsHeaders !== null) {
            foreach ($corsHeaders as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        if ($this->isNoStoreEndpoint($request)) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function isNoStoreEndpoint(Request $request): bool
    {
        $path = $request->getPath();
        if ($path === '/api/v1/me') {
            return true;
        }
        if ($path === '/api/v1/auth/token' || $path === '/api/v1/auth/revoke') {
            return true;
        }

        return false;
    }

    private function isCorsPreflight(Request $request): bool
    {
        if ($request->getMethod() !== 'OPTIONS') {
            return false;
        }

        if ($request->getHeader('origin') === null) {
            return false;
        }

        return $request->getHeader('access-control-request-method') !== null;
    }

    private function corsConfig(): array
    {
        $cors = $this->config['cors'] ?? [];
        return is_array($cors) ? $cors : [];
    }

    private function corsHeaders(?string $origin, array $corsConfig): ?array
    {
        $enabled = !empty($corsConfig['enabled']);
        if (!$enabled || $origin === null) {
            return null;
        }

        $origins = $corsConfig['origins'] ?? [];
        if (!is_array($origins)) {
            $origins = [];
        }

        if (!$this->originAllowed($origin, $origins)) {
            return null;
        }

        $methods = $corsConfig['methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $headers = $corsConfig['headers'] ?? ['Authorization', 'Content-Type', 'X-Requested-With'];

        if (!is_array($methods)) {
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }
        if (!is_array($headers)) {
            $headers = ['Authorization', 'Content-Type', 'X-Requested-With'];
        }

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => implode(', ', $headers),
            'Vary' => 'Origin',
        ];
    }

    private function originAllowed(string $origin, array $allowlist): bool
    {
        if ($allowlist === []) {
            return false;
        }

        foreach ($allowlist as $allowed) {
            if (!is_string($allowed) || $allowed === '') {
                continue;
            }
            if ($allowed === '*') {
                return true;
            }
            if (strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    private function origin(Request $request): ?string
    {
        $origin = $request->getHeader('origin');
        if ($origin === null || $origin === '') {
            return null;
        }

        return $origin;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->getHeader('authorization');
        if ($header === null) {
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    private function requiresAuth(Request $request): bool
    {
        if ($this->isPublicEndpoint($request)) {
            return false;
        }

        return true;
    }

    private function isPublicEndpoint(Request $request): bool
    {
        $path = $request->getPath();
        $method = $request->getMethod();

        if ($path === '/api/v1/ping') {
            return true;
        }

        if ($path === '/api/v1/auth/token' && $method === 'POST') {
            return true;
        }

        if ($method !== 'GET') {
            return false;
        }

        if ($path === '/api/v1/pages') {
            return true;
        }
        if (preg_match('#^/api/v1/pages/\\d+$#', $path)) {
            return true;
        }
        if (preg_match('#^/api/v1/pages/by-slug/[^/]+$#', $path)) {
            return true;
        }
        if ($path === '/api/v1/media') {
            return true;
        }
        if (preg_match('#^/api/v1/media/\\d+$#', $path)) {
            return true;
        }
        if (preg_match('#^/api/v1/media/\\d+/download$#', $path)) {
            return true;
        }
        if (preg_match('#^/api/v1/menus/[^/]+$#', $path)) {
            return true;
        }

        return false;
    }

    private function unauthorized(): Response
    {
        return ApiResponse::error('unauthorized', 'Unauthorized', [], 401, [
            'WWW-Authenticate' => 'Bearer',
        ]);
    }
}
