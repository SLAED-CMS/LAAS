<?php

declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class ApiTokensRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(
        int $userId,
        string $name,
        string $tokenHash,
        string $tokenPrefix,
        array $scopes,
        ?string $expiresAt
    ): int {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at, updated_at)
             VALUES (:user_id, :name, :token_hash, :token_prefix, :scopes, :last_used_at, :expires_at, :revoked_at, :created_at, :updated_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
            'scopes' => $this->encodeScopes($scopes),
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_tokens WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listByUser(int $userId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at, updated_at
             FROM api_tokens WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT api_tokens.id, api_tokens.user_id, api_tokens.name, api_tokens.token_prefix, api_tokens.scopes,
                    api_tokens.last_used_at, api_tokens.expires_at, api_tokens.revoked_at,
                    api_tokens.created_at, api_tokens.updated_at, users.username
             FROM api_tokens LEFT JOIN users ON users.id = api_tokens.user_id
             ORDER BY api_tokens.created_at DESC, api_tokens.id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM api_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS cnt FROM api_tokens');
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = :last_used_at, updated_at = :updated_at WHERE id = :id');
        $now = $this->now();
        $stmt->execute([
            'id' => $id,
            'last_used_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function revoke(int $id, ?int $userId = null): bool
    {
        $sql = 'UPDATE api_tokens SET revoked_at = :revoked_at, updated_at = :updated_at WHERE id = :id AND revoked_at IS NULL';
        $now = $this->now();
        $params = [
            'id' => $id,
            'revoked_at' => $now,
            'updated_at' => $now,
        ];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function encodeScopes(array $scopes): ?string
    {
        if ($scopes === []) {
            return null;
        }

        $encoded = json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : null;
    }
}
