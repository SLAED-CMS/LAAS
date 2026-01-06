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

    public function __construct(DatabaseManager $db)
    {
        $this->tokens = new ApiTokensRepository($db->pdo());
        $this->users = new UsersRepository($db->pdo());
    }

    /** @return array{token: string, token_id: int} */
    public function issueToken(int $userId, string $name, ?string $expiresAt = null): array
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $tokenId = $this->tokens->create($userId, $name, $hash, $expiresAt);

        return [
            'token' => $token,
            'token_id' => $tokenId,
        ];
    }

    /** @return array{user: array<string, mixed>, token: array<string, mixed>}|null */
    public function authenticate(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $row = $this->tokens->findByHash($hash);
        if ($row === null) {
            return null;
        }

        if (!empty($row['expires_at'])) {
            $expiresAt = strtotime((string) $row['expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                return null;
            }
        }

        $user = $this->users->findById((int) ($row['user_id'] ?? 0));
        if ($user === null) {
            return null;
        }

        if ((int) ($user['status'] ?? 0) !== 1) {
            return null;
        }

        $this->tokens->touchLastUsed((int) ($row['id'] ?? 0));

        return [
            'user' => $user,
            'token' => $row,
        ];
    }

    public function revoke(int $tokenId, int $userId): bool
    {
        return $this->tokens->revoke($tokenId, $userId);
    }
}
