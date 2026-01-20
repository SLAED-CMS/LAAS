<?php
declare(strict_types=1);

namespace Laas\Domain\ApiTokens;

interface ApiTokensServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function listTokens(?int $userId = null, int $limit = 100, int $offset = 0): array;

    public function countTokens(?int $userId = null): int;

    /**
     * @return array{
     *   token_id: int,
     *   token_prefix: string,
     *   token: string,
     *   scopes: array<int, string>,
     *   expires_at: string|null
     * }
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
     */
    public function rotateToken(
        int $userId,
        int $tokenId,
        string $name,
        array $scopes,
        mixed $expiresAt = null,
        bool $revokeOld = false
    ): array;

    public function revokeToken(int $tokenId, int $userId): void;

    /** @return array<int, string> */
    public function allowedScopes(): array;

    /** @return array<int, string> */
    public function defaultScopesSelection(): array;
}
