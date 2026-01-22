<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Http\ErrorResponse;
use Laas\Http\HeadlessMode;
use Laas\Http\Request;
use Laas\Http\Response;

final class ApiTokenAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DatabaseManager $db,
        private array $config,
        ?string $rootPath = null
    ) {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
    }

    private string $rootPath;

    public function process(Request $request, callable $next): Response
    {
        $result = $this->authenticate($request);
        if (isset($result['response']) && $result['response'] instanceof Response) {
            return $result['response'];
        }

        return $next($request);
    }

    /**
     * @return array{
     *   status: string,
     *   reason?: string,
     *   response?: Response,
     *   user?: array<string, mixed>,
     *   token?: array<string, mixed>
     * }
     */
    public function authenticate(Request $request): array
    {
        if (!$this->supports($request)) {
            return ['status' => 'skip'];
        }

        $token = $this->bearerToken($request);
        if ($token === null) {
            return ['status' => 'missing'];
        }

        if (!$this->db->healthCheck()) {
            return [
                'status' => 'error',
                'reason' => 'service_unavailable',
                'response' => $this->errorResponse($request, 'service_unavailable', 503),
            ];
        }

        $service = new ApiTokenService($this->db, $this->config, $this->rootPath);
        $auth = $service->authenticateWithReason($token);
        if (!$auth['ok']) {
            $reason = (string) ($auth['reason'] ?? 'invalid');
            $error = $reason === 'expired' ? 'auth.token_expired' : 'auth.invalid_token';
            return [
                'status' => 'invalid',
                'reason' => $reason,
                'response' => $this->unauthorized($request, $error),
            ];
        }

        $user = $auth['user'] ?? null;
        $row = $auth['token'] ?? null;
        if (!is_array($user) || !is_array($row)) {
            return [
                'status' => 'invalid',
                'reason' => 'invalid',
                'response' => $this->unauthorized($request, 'auth.invalid_token'),
            ];
        }

        $this->setAuthAttributes($request, $user, $row);

        $required = $this->requiredScopes($request);
        if ($required !== []) {
            $tokenScopes = $this->normalizeScopes($row['scopes'] ?? null);
            if (!$this->allowsScopes($tokenScopes, $required)) {
                $meta = [
                    'route' => HeadlessMode::resolveRoute($request),
                ];
                if ($this->isDebug()) {
                    $meta['required_scopes'] = $required;
                    $meta['token_scopes'] = $tokenScopes;
                }
                return [
                    'status' => 'forbidden',
                    'reason' => 'forbidden_scope',
                    'response' => $this->errorResponse($request, 'api.auth.forbidden_scope', 403, $meta),
                    'user' => $user,
                    'token' => $row,
                ];
            }
        }

        return [
            'status' => 'ok',
            'reason' => 'ok',
            'user' => $user,
            'token' => $row,
        ];
    }

    private function supports(Request $request): bool
    {
        return str_starts_with($request->getPath(), '/api/');
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

    private function setAuthAttributes(Request $request, array $user, array $tokenRow): void
    {
        $request->setAttribute('auth_user_id', (int) ($user['id'] ?? 0));
        $request->setAttribute('auth_scopes', $this->normalizeScopes($tokenRow['scopes'] ?? null));
        $request->setAttribute('auth_token_id', (int) ($tokenRow['id'] ?? 0));
    }

    private function unauthorized(Request $request, string $error): Response
    {
        return $this->errorResponse($request, $error, 401)
            ->withHeader('WWW-Authenticate', 'Bearer');
    }

    private function errorResponse(Request $request, string $error, int $status, array $meta = []): Response
    {
        if ($meta === []) {
            $meta = [
                'route' => HeadlessMode::resolveRoute($request),
            ];
        }
        return ErrorResponse::respond($request, $error, [], $status, $meta, 'api.token.auth');
    }

    private function normalizeToken(string $token): ?string
    {
        $token = trim($token);
        if ($token === '' || preg_match('/\\s/', $token) === 1) {
            return null;
        }

        return $token;
    }

    /** @return array<int, string> */
    private function requiredScopes(Request $request): array
    {
        $map = $this->config['routes_scopes'] ?? null;
        if (!is_array($map)) {
            return [];
        }

        $method = strtoupper($request->getMethod());
        $pattern = $request->getAttribute('route.pattern');
        $path = is_string($pattern) && $pattern !== '' ? $pattern : $request->getPath();
        $signature = $method . ' ' . $path;
        $scopes = $map[$signature] ?? null;
        if (!is_array($scopes)) {
            return [];
        }

        $out = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = strtolower(trim($scope));
            if ($scope === '' || $scope === '*') {
                continue;
            }
            $out[] = $scope;
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    private function normalizeScopes(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = array_map(static fn ($item): string => strtolower(trim((string) $item)), $raw);
            $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
            if (in_array('*', $items, true)) {
                return ['*'];
            }
            return array_values(array_unique($items));
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = strtolower(trim($item));
            if ($item !== '') {
                $items[] = $item;
            }
        }
        if (in_array('*', $items, true)) {
            return ['*'];
        }

        return array_values(array_unique($items));
    }

    /** @param array<int, string> $tokenScopes @param array<int, string> $requiredScopes */
    private function allowsScopes(array $tokenScopes, array $requiredScopes): bool
    {
        if ($requiredScopes === []) {
            return true;
        }
        if (in_array('*', $tokenScopes, true)) {
            return true;
        }

        $map = array_flip($tokenScopes);
        foreach ($requiredScopes as $scope) {
            if (!isset($map[$scope])) {
                return false;
            }
        }

        return true;
    }

    private function isDebug(): bool
    {
        $env = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: null;
        if ($env !== null && $env !== '') {
            $parsed = filter_var($env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $configPath = $this->rootPath . '/config/app.php';
        if (is_file($configPath)) {
            $config = require $configPath;
            if (is_array($config)) {
                return (bool) ($config['debug'] ?? false);
            }
        }

        return false;
    }
}
