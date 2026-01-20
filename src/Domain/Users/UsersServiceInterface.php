<?php
declare(strict_types=1);

namespace Laas\Domain\Users;

interface UsersServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    public function count(array $filters = []): int;

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array;

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array;

    /** @return array<int, array<int, string>> */
    public function rolesForUsers(array $userIds): array;

    /** @return array<int, string> */
    public function rolesForUser(int $userId): array;

    public function updateLoginMeta(int $userId, string $ip): void;

    /** @return array<string, mixed>|null */
    public function getTotpData(int $userId): ?array;

    public function setTotpSecret(int $userId, ?string $secret): void;

    public function setTotpEnabled(int $userId, bool $enabled): void;

    public function setBackupCodes(int $userId, ?string $codes): void;

    public function isAdmin(int $userId): bool;

    public function setStatus(int $userId, int $status): void;

    public function setPasswordHash(int $userId, string $hash): void;

    public function setAdminRole(int $userId, bool $isAdmin): void;

    public function createPasswordResetToken(string $email, string $hashedToken, int $expiresInSeconds = 3600): void;

    /** @return array<string, mixed>|null */
    public function findPasswordResetByToken(string $hashedToken): ?array;

    public function deletePasswordResetToken(string $hashedToken): void;

    public function deletePasswordResetByEmail(string $email): void;

    public function isPasswordResetTokenValid(array $tokenRecord): bool;

    public function delete(int $userId): void;
}
