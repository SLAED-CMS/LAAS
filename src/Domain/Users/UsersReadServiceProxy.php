<?php
declare(strict_types=1);

namespace Laas\Domain\Users;

use Laas\Domain\Support\ReadOnlyProxy;

final class UsersReadServiceProxy extends ReadOnlyProxy implements UsersReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function count(array $filters = []): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<int, string>> */
    public function rolesForUsers(array $userIds): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, string> */
    public function rolesForUser(int $userId): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function getTotpData(int $userId): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function isAdmin(int $userId): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function findPasswordResetByToken(string $hashedToken): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function isPasswordResetTokenValid(array $tokenRecord): bool
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
