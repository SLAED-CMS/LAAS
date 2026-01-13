<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Api\ApiResponse;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Api\ApiTokenService;
use Laas\Auth\AuthorizationService;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\AuditLogger;
use Laas\Support\AuditSpamGuard;

final class ApiMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DatabaseManager $db,
        private AuthorizationService $authorization,
        private array $config,
        ?string $rootPath = null
    ) {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->tokenAuth = new ApiTokenAuthMiddleware($db, $this->config, $this->rootPath);
    }

    private string $rootPath;
    private ApiTokenAuthMiddleware $tokenAuth;

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
        $corsHeaders = $this->corsHeaders($origin, $cors, $request);

        if ($this->isCorsPreflight($request)) {
            if ($corsHeaders === null || !$corsHeaders['allowed']) {
                return $this->applyHeaders(ApiResponse::error('forbidden', 'Forbidden', [], 403), $request, null);
            }

            return $this->applyHeaders(new Response('', 204), $request, $corsHeaders['headers']);
        }

        $token = $this->bearerToken($request);
        $authReason = 'missing_token';
        $tokenResult = $this->tokenAuth->authenticate($request);
        if (isset($tokenResult['response']) && $tokenResult['response'] instanceof Response) {
            $authReason = (string) ($tokenResult['reason'] ?? 'invalid');
            $token = $this->bearerToken($request);
            $this->auditAuthFailure($request, $authReason, $token, $tokenResult['token'] ?? null);
            return $this->applyHeaders($tokenResult['response'], $request, $corsHeaders);
        }
        if (($tokenResult['status'] ?? '') === 'ok') {
            $request->setAttribute('api.user', $tokenResult['user'] ?? null);
            $request->setAttribute('api.token', $tokenResult['token'] ?? null);
            $authReason = 'ok';
        }

        if ($this->requiresAuth($request)) {
            $user = $request->getAttribute('api.user');
            if (!is_array($user)) {
                $this->auditAuthFailure($request, $authReason, $token, $request->getAttribute('api.token'));
                return $this->applyHeaders($this->unauthorized($request), $request, $corsHeaders);
            }

            if (!$this->authorization->can($user, 'api.access')) {
                return $this->applyHeaders(ApiResponse::error('forbidden', 'Forbidden', [], 403), $request, $corsHeaders);
            }
        }

        $response = $next($request);

        $response = $this->normalizeResponse($request, $response);

        return $this->applyHeaders($response, $request, $corsHeaders['headers'] ?? $corsHeaders);
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

    private function corsHeaders(?string $origin, array $corsConfig, Request $request): ?array
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
        $maxAge = (int) ($corsConfig['max_age'] ?? 600);

        if (!is_array($methods)) {
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }
        if (!is_array($headers)) {
            $headers = ['Authorization', 'Content-Type', 'X-Requested-With'];
        }

        $requestedMethod = $request->getHeader('access-control-request-method');
        $requestedHeaders = $request->getHeader('access-control-request-headers');

        if ($this->isCorsPreflight($request)) {
            if ($requestedMethod === null || !in_array(strtoupper($requestedMethod), array_map('strtoupper', $methods), true)) {
                return null;
            }

            if ($requestedHeaders !== null) {
                $headerList = array_map('trim', explode(',', $requestedHeaders));
                foreach ($headerList as $hdr) {
                    if ($hdr === '') {
                        continue;
                    }
                    if (!in_array(strtolower($hdr), array_map('strtolower', $headers), true)) {
                        return null;
                    }
                }
            }

            return [
                'allowed' => true,
                'headers' => [
                    'Access-Control-Allow-Origin' => $origin,
                    'Access-Control-Allow-Methods' => implode(', ', $methods),
                    'Access-Control-Allow-Headers' => implode(', ', $headers),
                    'Access-Control-Max-Age' => (string) max(0, $maxAge),
                    'Vary' => 'Origin',
                ],
            ];
        }

        return [
            'allowed' => true,
            'headers' => [
                'Access-Control-Allow-Origin' => $origin,
                'Vary' => 'Origin',
            ],
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
        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            $token = trim(substr($header, 7));
            return $this->normalizeToken($token);
        }

        $header = $request->getHeader('x-api-token');
        if (is_string($header) && $header !== '') {
            return $this->normalizeToken($header);
        }

        return null;
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

    private function unauthorized(Request $request): Response
    {
        $meta = [
            'route' => \Laas\Http\HeadlessMode::resolveRoute($request),
        ];
        return ErrorResponse::respond($request, ErrorCode::API_TOKEN_INVALID, [], 401, $meta, 'api.auth')
            ->withHeader('WWW-Authenticate', 'Bearer');
    }

    private function auditAuthFailure(Request $request, string $reason, ?string $plainToken, mixed $tokenRow): void
    {
        $guard = new AuditSpamGuard($this->rootPath, 60);
        $ip = $request->ip();
        $tokenPrefix = null;

        if (is_array($tokenRow) && isset($tokenRow['token_prefix'])) {
            $tokenPrefix = (string) $tokenRow['token_prefix'];
        } elseif ($plainToken !== null) {
            $tokenPrefix = $this->extractTokenPrefix($plainToken);
        }

        $key = 'api.auth.failed:' . ($tokenPrefix !== null ? 't:' . $tokenPrefix : 'ip:' . $ip) . ':' . date('YmdHi');
        if (!$guard->shouldLog($key)) {
            return;
        }

        (new AuditLogger($this->db, $request->session()))->log(
            'api.auth.failed',
            'api',
            null,
            [
                'reason' => $reason,
                'token_prefix' => $tokenPrefix,
                'path' => $request->getPath(),
            ],
            null,
            $ip
        );
    }

    private function extractTokenPrefix(string $plainToken): ?string
    {
        if (str_starts_with($plainToken, 'LAAS_')) {
            $raw = substr($plainToken, 5);
            $parts = explode('.', $raw, 2);
            if (count($parts) === 2 && $parts[0] !== '') {
                return substr($parts[0], 0, 16);
            }
        }

        return substr(hash('sha256', $plainToken), 0, 12);
    }

    private function normalizeToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '' || preg_match('/\\s/', $token) === 1) {
            return null;
        }

        return $token;
    }
}
