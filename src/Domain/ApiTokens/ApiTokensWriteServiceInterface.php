<?php
declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

interface ApiTokensWriteServiceInterface
{
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
    public function createToken(int $userId, string $name, array $scopes, mixed $expiresAt = null): array;

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
    ): array;

    /** @mutation */
    public function revokeToken(int $tokenId, int $userId): void;
}
