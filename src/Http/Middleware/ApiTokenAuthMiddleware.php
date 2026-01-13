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
        if ($header === null) {
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    private function setAuthAttributes(Request $request, array $user, array $tokenRow): void
    {
        $request->setAttribute('auth_user_id', (int) ($user['id'] ?? 0));
        $request->setAttribute('auth_scopes', is_array($tokenRow['scopes'] ?? null) ? $tokenRow['scopes'] : []);
        $request->setAttribute('auth_token_id', (int) ($tokenRow['id'] ?? 0));
    }

    private function unauthorized(Request $request, string $error): Response
    {
        return $this->errorResponse($request, $error, 401)
            ->withHeader('WWW-Authenticate', 'Bearer');
    }

    private function errorResponse(Request $request, string $error, int $status): Response
    {
        $meta = [
            'route' => HeadlessMode::resolveRoute($request),
        ];
        return ErrorResponse::respond($request, $error, [], $status, $meta, 'api.token.auth');
    }
}
