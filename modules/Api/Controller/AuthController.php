<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\SettingsRepository;
use Laas\Database\Repositories\UsersRepository;
use Laas\Http\ErrorCode;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\Csrf;
use Laas\Support\AuditLogger;
use Throwable;

final class AuthController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function token(Request $request): Response
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [], 503);
        }

        $input = $this->readInput($request);
        $name = trim((string) ($input['name'] ?? ''));
        $expiresRaw = trim((string) ($input['expires_at'] ?? ''));
        $scopesInput = $this->readScopes($input);

        $errors = [];
        if ($name === '' || strlen($name) > 120) {
            $errors['name'] = 'invalid';
        }

        $expiresAt = null;
        if ($expiresRaw !== '') {
            $expiresAt = $this->parseExpiresAt($expiresRaw);
            if ($expiresAt === null) {
                $errors['expires_at'] = 'invalid';
            }
        }

        $service = new ApiTokenService($this->db, $this->apiConfig(), dirname(__DIR__, 3));
        $allowedScopes = $service->allowedScopes();
        $invalidScopes = $this->invalidScopes($scopesInput, $allowedScopes);
        $scopes = $service->normalizeScopes($scopesInput);
        if ($scopesInput === [] && $allowedScopes !== []) {
            $scopes = $allowedScopes;
        }
        if ($invalidScopes !== []) {
            $errors['scopes'] = 'invalid';
        }

        $userId = $this->sessionUserId($request);
        $mode = $this->tokenIssueMode();

        if ($userId !== null) {
            if (!$this->canManageTokens($userId)) {
                return ApiResponse::error(ErrorCode::RBAC_DENIED, 'Forbidden', [], 403);
            }

            if (!$this->validateCsrf($request)) {
                return ApiResponse::error(ErrorCode::CSRF_INVALID, 'CSRF Token Mismatch', [], 419);
            }
        } else {
            if ($mode !== 'admin_or_password') {
                return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
            }

            $username = trim((string) ($input['username'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            if ($username === '' || $password === '') {
                return ApiResponse::error(ErrorCode::AUTH_INVALID, 'Unauthorized', [], 401);
            }

            $userId = $this->verifyCredentials($username, $password);
            if ($userId === null) {
                return ApiResponse::error(ErrorCode::AUTH_INVALID, 'Unauthorized', [], 401);
            }
        }

        if ($errors !== []) {
            return ApiResponse::error(ErrorCode::VALIDATION_FAILED, 'Validation failed', $errors, 422);
        }

        $result = $service->createToken($userId, $name, $scopes, $expiresAt);

        (new AuditLogger($this->db, $request->session()))->log(
            'api.token.created',
            'api_token',
            (int) ($result['token_id'] ?? 0),
            [
                'name' => $name,
                'expires_at' => $expiresAt,
                'scopes' => $scopes,
                'token_prefix' => (string) ($result['token_prefix'] ?? ''),
            ],
            $userId,
            $request->ip()
        );

        return ApiResponse::ok([
            'token' => $result['token'],
            'token_id' => (int) ($result['token_id'] ?? 0),
            'token_prefix' => (string) ($result['token_prefix'] ?? ''),
            'name' => $name,
            'expires_at' => $expiresAt,
            'scopes' => $scopes,
        ], [], 201, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function me(Request $request): Response
    {
        $user = $request->getAttribute('api.user');
        $token = $request->getAttribute('api.token');
        if (!is_array($user) || !is_array($token)) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        return ApiResponse::ok([
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'username' => (string) ($user['username'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'status' => (int) ($user['status'] ?? 0),
                'created_at' => (string) ($user['created_at'] ?? ''),
                'last_login_at' => (string) ($user['last_login_at'] ?? ''),
            ],
            'token' => [
                'id' => (int) ($token['id'] ?? 0),
                'name' => (string) ($token['name'] ?? ''),
                'last_used_at' => (string) ($token['last_used_at'] ?? ''),
                'expires_at' => (string) ($token['expires_at'] ?? ''),
                'created_at' => (string) ($token['created_at'] ?? ''),
            ],
        ], [], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function revoke(Request $request): Response
    {
        $user = $request->getAttribute('api.user');
        $token = $request->getAttribute('api.token');
        if (!is_array($user) || !is_array($token)) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        if ($this->db === null || !$this->db->healthCheck()) {
            return ApiResponse::error(ErrorCode::SERVICE_UNAVAILABLE, 'Service Unavailable', [], 503);
        }

        $tokenId = (int) ($token['id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        if ($tokenId <= 0 || $userId <= 0) {
            return ApiResponse::error(ErrorCode::AUTH_REQUIRED, 'Unauthorized', [], 401);
        }

        $service = new ApiTokenService($this->db);
        $ok = $service->revoke($tokenId, $userId);
        if (!$ok) {
            return ApiResponse::error(ErrorCode::NOT_FOUND, 'Not Found', [], 404);
        }

        (new AuditLogger($this->db, $request->session()))->log(
            'api.token.revoked',
            'api_token',
            $tokenId,
            [
                'token_id' => $tokenId,
                'user_id' => $userId,
            ],
            $userId,
            $request->ip()
        );

        return ApiResponse::ok([
            'revoked' => true,
        ], [], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function readInput(Request $request): array
    {
        $contentType = strtolower((string) ($request->getHeader('content-type') ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = trim($request->getBody());
            if ($raw === '') {
                return [];
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }

        return $request->getPost();
    }

    private function parseExpiresAt(string $value): ?string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function tokenIssueMode(): string
    {
        $mode = 'admin';
        try {
            $configPath = dirname(__DIR__, 3) . '/config/api.php';
            if (is_file($configPath)) {
                $config = require $configPath;
                if (is_array($config) && isset($config['token_issue_mode'])) {
                    $mode = (string) $config['token_issue_mode'];
                }
            }
        } catch (Throwable) {
            return $mode;
        }

        try {
            $repo = new SettingsRepository($this->db->pdo());
            if ($repo->has('api.token_issue_mode')) {
                $value = (string) $repo->get('api.token_issue_mode', $mode);
                if ($value !== '') {
                    $mode = $value;
                }
            }
        } catch (Throwable) {
            return $mode;
        }

        return $mode;
    }

    /** @return array<string, mixed> */
    private function apiConfig(): array
    {
        $configPath = dirname(__DIR__, 3) . '/config/api.php';
        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }

    private function canManageTokens(int $userId): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'api.tokens.manage');
        } catch (Throwable) {
            return false;
        }
    }

    private function sessionUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function verifyCredentials(string $username, string $password): ?int
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            $users = new UsersRepository($this->db->pdo());
            $user = $users->findByUsername($username);
            if ($user === null) {
                return null;
            }

            if ((int) ($user['status'] ?? 0) !== 1) {
                return null;
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if (!password_verify($password, $hash)) {
                return null;
            }

            return (int) ($user['id'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    private function validateCsrf(Request $request): bool
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return false;
        }

        $csrf = new Csrf($session);
        $token = $request->post(Csrf::FORM_KEY);
        if ($token === null || $token === '') {
            $token = $request->header(Csrf::HEADER_KEY);
        }

        return $csrf->validate($token);
    }

    /** @return array<int, string> */
    private function readScopes(array $input): array
    {
        $raw = $input['scopes'] ?? [];
        if (is_string($raw)) {
            $parts = array_map('trim', explode(',', $raw));
            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    private function invalidScopes(array $scopes, array $allowlist): array
    {
        if ($scopes === []) {
            return [];
        }

        $allowed = array_flip($allowlist);
        $invalid = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            if (!isset($allowed[$scope])) {
                $invalid[] = $scope;
            }
        }

        return array_values(array_unique($invalid));
    }
}
