<?php

declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

use DateTimeImmutable;
use DateTimeInterface;
use Laas\Api\ApiTokenService;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\ApiTokensRepository;
use RuntimeException;
use Throwable;

class ApiTokensService implements ApiTokensServiceInterface, ApiTokensReadServiceInterface, ApiTokensWriteServiceInterface
{
    private ?ApiTokensRepository $repository = null;
    private ?ApiTokenService $tokenService = null;
    private array $config;
    private string $rootPath;

    public function __construct(
        private DatabaseManager $db,
        array $config = [],
        ?string $rootPath = null
    ) {
        $this->rootPath = $rootPath ?? dirname(__DIR__, 3);
        $this->config = $config !== [] ? $config : $this->loadConfig();
    }

    /** @return array<int, array<string, mixed>> */
    public function listTokens(?int $userId = null, int $limit = 100, int $offset = 0): array
    {
        $rows = $userId === null
            ? $this->repository()->listAll($limit, $offset)
            : $this->repository()->listByUser($userId, $limit, $offset);

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->normalizeRow($row);
        }

        return $normalized;
    }

    public function countTokens(?int $userId = null): int
    {
        return $userId === null
            ? $this->repository()->countAll()
            : $this->repository()->countByUser($userId);
    }

    /** @return array<string, mixed>|null */
    public function findToken(int $tokenId): ?array
    {
        $row = $this->repository()->findById($tokenId);
        if ($row === null) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * @return array{
     *   token_id: int,
     *   token_prefix: string,
     *   token: string,
     *   scopes: array<int, string>,
     *   expires_at: string|null
     * }
     * @mutation
     */
    public function createToken(
        int $userId,
        string $name,
        array $scopes,
        mixed $expiresAt = null
    ): array {
        $name = trim($name);
        $fields = [];
        if ($name === '' || strlen($name) > 120) {
            $fields['name'] = ['invalid'];
        }

        $expires = $this->normalizeExpiresAt($expiresAt, $fields);
        $allowedScopes = $this->allowedScopes();
        $scopes = $this->normalizeScopes($scopes, $allowedScopes, $fields);
        if ($scopes === [] && $allowedScopes !== []) {
            $scopes = $allowedScopes;
        }

        if ($fields !== []) {
            throw new ApiTokensServiceException('validation', ['fields' => $fields]);
        }

        $this->enforceLimit($userId);

        $created = $this->tokenService()->createToken($userId, $name, $scopes, $expires);

        return [
            'token_id' => (int) ($created['token_id'] ?? 0),
            'token_prefix' => (string) ($created['token_prefix'] ?? ''),
            'token' => (string) ($created['token'] ?? ''),
            'scopes' => $scopes,
            'expires_at' => $expires,
        ];
    }

    /**
     * @return array{
     *   token_id: int,
     *   token_prefix: string,
     *   token: string,
     *   scopes: array<int, string>,
     *   expires_at: string|null,
     *   name: string,
     *   revoked_old: bool
     * }
     * @mutation
     */
    public function rotateToken(
        int $userId,
        int $tokenId,
        string $name,
        array $scopes,
        mixed $expiresAt = null,
        bool $revokeOld = false
    ): array {
        $existing = $this->repository()->findById($tokenId);
        if ($existing === null || (int) ($existing['user_id'] ?? 0) !== $userId) {
            throw new ApiTokensServiceException('not_found');
        }

        $resolvedName = trim($name);
        if ($resolvedName === '') {
            $resolvedName = $this->rotateName((string) ($existing['name'] ?? ''));
        }

        $fields = [];
        if ($resolvedName === '' || strlen($resolvedName) > 120) {
            $fields['name'] = ['invalid'];
        }

        $expires = $this->normalizeExpiresAt($expiresAt, $fields);
        $allowedScopes = $this->allowedScopes();
        $scopes = $this->normalizeScopes($scopes, $allowedScopes, $fields);
        if ($scopes === [] && $allowedScopes !== []) {
            $existingScopes = $this->decodeScopes($existing['scopes'] ?? null);
            $scopes = $existingScopes !== [] ? $existingScopes : $allowedScopes;
        }

        if ($fields !== []) {
            throw new ApiTokensServiceException('validation', ['fields' => $fields]);
        }

        $this->enforceLimit($userId);

        $created = $this->tokenService()->createToken($userId, $resolvedName, $scopes, $expires);
        $revokedOld = false;
        if ($revokeOld) {
            $revokedOld = $this->repository()->revoke($tokenId, $userId);
        }

        return [
            'token_id' => (int) ($created['token_id'] ?? 0),
            'token_prefix' => (string) ($created['token_prefix'] ?? ''),
            'token' => (string) ($created['token'] ?? ''),
            'scopes' => $scopes,
            'expires_at' => $expires,
            'name' => $resolvedName,
            'revoked_old' => $revokedOld,
        ];
    }

    /** @mutation */
    public function revokeToken(int $tokenId, int $userId): void
    {
        $ok = $this->repository()->revoke($tokenId, $userId);
        if (!$ok) {
            throw new ApiTokensServiceException('not_found');
        }
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
            if ($scope !== '') {
                $out[] = $scope;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> */
    public function defaultScopesSelection(): array
    {
        return $this->allowedScopes();
    }

    private function repository(): ApiTokensRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new ApiTokensRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }

    private function tokenService(): ApiTokenService
    {
        if ($this->tokenService !== null) {
            return $this->tokenService;
        }

        $this->tokenService = new ApiTokenService($this->db, $this->config, $this->rootPath);
        return $this->tokenService;
    }

    /** @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        $scopes = $this->decodeScopes($row['scopes'] ?? null);
        $expiresAt = $this->normalizeDate($row['expires_at'] ?? null);
        $revokedAt = $this->normalizeDate($row['revoked_at'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'token_prefix' => (string) ($row['token_prefix'] ?? ''),
            'scopes' => $scopes,
            'last_used_at' => $this->normalizeDate($row['last_used_at'] ?? null),
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'username' => isset($row['username']) ? (string) $row['username'] : null,
            'status' => $this->status((string) ($expiresAt ?? ''), (string) ($revokedAt ?? '')),
        ];
    }

    /** @param array<string, mixed> $fields */
    private function normalizeExpiresAt(mixed $value, array &$fields): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $fields['expires_at'] = ['invalid'];
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $fields['expires_at'] = ['invalid'];
            return null;
        }
    }

    /** @param array<string, mixed> $fields */
    private function normalizeScopes(array $scopes, array $allowlist, array &$fields): array
    {
        $allowed = array_flip($allowlist);
        $out = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if ($scope === '') {
                continue;
            }
            if (!isset($allowed[$scope])) {
                $fields['scopes'] = ['invalid'];
                continue;
            }
            $out[] = $scope;
        }

        return array_values(array_unique($out));
    }

    private function enforceLimit(int $userId): void
    {
        $limit = (int) ($this->config['token_limit'] ?? $this->config['token_max'] ?? 0);
        if ($limit <= 0) {
            return;
        }

        $count = $this->repository()->countByUser($userId);
        if ($count >= $limit) {
            throw new ApiTokensServiceException('limit', [
                'limit' => $limit,
                'count' => $count,
            ]);
        }
    }

    /** @return array<int, string> */
    private function decodeScopes(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn ($item): string => (string) $item, $raw)));
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
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

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function status(string $expiresAt, string $revokedAt): string
    {
        if ($revokedAt !== '') {
            return 'revoked';
        }
        if ($expiresAt !== '') {
            $ts = strtotime($expiresAt);
            if ($ts !== false && $ts < time()) {
                return 'expired';
            }
        }

        return 'active';
    }

    private function rotateName(string $name): string
    {
        if ($name === '') {
            return 'Rotated token';
        }

        return $name . ' (rotated)';
    }

    /** @return array<string, mixed> */
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
