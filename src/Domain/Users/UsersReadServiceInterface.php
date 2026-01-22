<?php

declare(strict_types=1);

namespace Laas\Domain\Users;

interface UsersReadServiceInterface
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

    /** @return array<string, mixed>|null */
    public function getTotpData(int $userId): ?array;

    public function isAdmin(int $userId): bool;

    /** @return array<string, mixed>|null */
    public function findPasswordResetByToken(string $hashedToken): ?array;

    public function isPasswordResetTokenValid(array $tokenRecord): bool;
}
