<?php
declare(strict_types=1);

namespace Laas\Domain\Rbac;

interface RbacServiceInterface
{
    public function userHasPermission(int $userId, string $permission): bool;

    public function userHasAnyPermission(int $userId, array $permissions): bool;

    public function userHasRole(int $userId, string $roleName): bool;

    /** @return array<int, array<string, mixed>> */
    public function listRoles(): array;

    /** @return array<string, mixed>|null */
    public function findRole(int $id): ?array;

    public function findRoleIdByName(string $name): ?int;

    /** @mutation */
    public function createRole(string $name, ?string $title = null): int;

    /** @mutation */
    public function updateRole(int $id, string $name, ?string $title = null): void;

    /** @mutation */
    public function deleteRole(int $id): void;

    /** @return array<int, array<string, mixed>> */
    public function listPermissions(): array;

    /** @return array<int, string> */
    public function listRolePermissions(int $roleId): array;

    /**
     * @param array<int, int> $permissionIds
     * @return array{added: array<int, int>, removed: array<int, int>}
     * @mutation
     */
    public function setRolePermissions(int $roleId, array $permissionIds): array;

    /** @return array<int, int> */
    public function resolvePermissionIdsByName(array $names): array;

    /** @return array<int, string> */
    public function resolvePermissionNamesByIds(array $ids): array;
}
