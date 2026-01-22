<?php

declare(strict_types=1);

namespace Laas\Api;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use Laas\Database\Repositories\UsersRepository;

final class ApiTokenService
{
    private ApiTokensRepository $tokens;
    private UsersRepository $users;
    private array $config;
    private string $rootPath;
    private const PREFIX_LENGTH = 12;
    private const SECRET_BYTES = 32;
    private const TOUCH_THROTTLE_SECONDS = 60;

    public function __construct(DatabaseManager $db, array $config = [], ?string $rootPath = null)
    {
        $this->tokens = new ApiTokensRepository($db->pdo());
        $this->users = new UsersRepository($db->pdo());
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->config = $config !== [] ? $config : $this->loadConfig();
    }

    /** @return array{token: string, token_id: int, token_prefix: string, scopes: array<int, string>, record: array<string, mixed>} */
    public function createToken(int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === [] && $this->defaultScopes() !== []) {
            $scopes = $this->defaultScopes();
        }

        $prefix = $this->generatePrefix();
        $secret = $this->base64UrlEncode(random_bytes(self::SECRET_BYTES));
        $token = 'LAAS_' . $prefix . '.' . $secret;
        $hash = hash('sha256', $token);
        $tokenId = $this->tokens->create($userId, $name, $hash, $prefix, $scopes, $expiresAt);

        return [
            'token' => $token,
            'token_id' => $tokenId,
            'token_prefix' => $prefix,
            'scopes' => $scopes,
            'record' => [
                'id' => $tokenId,
                'user_id' => $userId,
                'name' => $name,
                'token_prefix' => $prefix,
                'scopes' => $scopes,
                'expires_at' => $expiresAt,
            ],
        ];
    }

    /** @return array{token: string, token_id: int} */
    public function issueToken(int $userId, string $name, ?string $expiresAt = null): array
    {
        $issued = $this->createToken($userId, $name, $this->defaultScopes(), $expiresAt);
        return [
            'token' => $issued['token'],
            'token_id' => (int) $issued['token_id'],
        ];
    }

    /** @return array{user_id: int, scopes: array<int, string>, token_id: int}|null */
    public function verifyToken(string $token): ?array
    {
        $result = $this->authenticateWithReason($token);
        if (!$result['ok']) {
            return null;
        }

        $user = $result['user'] ?? null;
        $row = $result['token'] ?? null;
        if (!is_array($user) || !is_array($row)) {
            return null;
        }

        return [
            'user_id' => (int) ($user['id'] ?? 0),
            'scopes' => $this->decodeScopes($row['scopes'] ?? null),
            'token_id' => (int) ($row['id'] ?? 0),
        ];
    }

    /** @return array{user: array<string, mixed>, token: array<string, mixed>}|null */
    public function authenticate(string $token): ?array
    {
        $result = $this->authenticateWithReason($token);
        if (!$result['ok']) {
            return null;
        }

        return [
            'user' => $result['user'],
            'token' => $result['token'],
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   reason: string,
     *   user?: array<string, mixed>,
     *   token?: array<string, mixed>
     * }
     */
    public function authenticateWithReason(string $token): array
    {
        $hash = hash('sha256', $token);
        $row = $this->tokens->findByHash($hash);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $storedHash = (string) ($row['token_hash'] ?? '');
        if ($storedHash === '' || !hash_equals($storedHash, $hash)) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        if (!empty($row['revoked_at'])) {
            return ['ok' => false, 'reason' => 'revoked'];
        }

        if (!empty($row['expires_at'])) {
            $expiresAt = strtotime((string) $row['expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                return ['ok' => false, 'reason' => 'expired'];
            }
        }

        $user = $this->users->findById((int) ($row['user_id'] ?? 0));
        if ($user === null) {
            return ['ok' => false, 'reason' => 'user_not_found'];
        }

        if ((int) ($user['status'] ?? 0) !== 1) {
            return ['ok' => false, 'reason' => 'user_inactive'];
        }

        $tokenId = (int) ($row['id'] ?? 0);
        if ($tokenId > 0 && $this->shouldTouch((string) ($row['last_used_at'] ?? ''))) {
            $this->tokens->touchLastUsed($tokenId);
        }

        $row['scopes'] = $this->decodeScopes($row['scopes'] ?? null);

        return [
            'ok' => true,
            'reason' => 'ok',
            'user' => $user,
            'token' => $row,
        ];
    }

    public function revoke(int $tokenId, int $userId): bool
    {
        return $this->tokens->revoke($tokenId, $userId);
    }

    public function revokeToken(int $tokenId, int $actorUserId): bool
    {
        return $this->tokens->revoke($tokenId, $actorUserId);
    }

    /** @return array<int, array<string, mixed>> */
    public function listTokens(int $userId): array
    {
        $rows = $this->tokens->listByUser($userId, 100, 0);
        foreach ($rows as $idx => $row) {
            $rows[$idx]['scopes'] = $this->decodeScopes($row['scopes'] ?? null);
        }
        return $rows;
    }

    /** @return array<int, string> */
    public function allowedScopes(): array
    {
        $scopes = $this->config['token_scopes'] ?? [];
        if (!is_array($scopes)) {
            return [];
        }

        $out = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            $out[] = $scope;
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    public function normalizeScopes(array $scopes): array
    {
        $allowed = array_flip($this->allowedScopes());
        $out = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope === '' || !isset($allowed[$scope])) {
                continue;
            }
            $out[] = $scope;
        }

        return array_values(array_unique($out));
    }

    public function touchLastUsed(int $tokenId): void
    {
        $this->tokens->touchLastUsed($tokenId);
    }

    /** @return array<int, string> */
    private function defaultScopes(): array
    {
        return $this->allowedScopes();
    }

    private function generatePrefix(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, self::PREFIX_LENGTH);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function shouldTouch(string $lastUsedAt): bool
    {
        if ($lastUsedAt === '') {
            return true;
        }

        $ts = strtotime($lastUsedAt);
        if ($ts === false) {
            return true;
        }

        return $ts <= (time() - self::TOUCH_THROTTLE_SECONDS);
    }

    /** @return array<int, string> */
    private function decodeScopes(mixed $scopes): array
    {
        if (is_array($scopes)) {
            return array_values(array_filter(array_map(static fn ($item): string => (string) $item, $scopes)));
        }
        if (!is_string($scopes) || $scopes === '') {
            return [];
        }

        $decoded = json_decode($scopes, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
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

    private function loadConfig(): array
    {
        $path = $this->rootPath . '/config/api.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }
}
