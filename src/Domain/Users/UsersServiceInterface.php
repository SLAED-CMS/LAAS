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

    /** @return array<int, array<int, string>> */
    public function rolesForUsers(array $userIds): array;

    public function isAdmin(int $userId): bool;

    public function setStatus(int $userId, int $status): void;

    public function setPasswordHash(int $userId, string $hash): void;

    public function setAdminRole(int $userId, bool $isAdmin): void;

    public function delete(int $userId): void;
}
