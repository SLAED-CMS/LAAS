<?php
declare(strict_types=1);

namespace Laas\Domain\Rbac;

use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\PermissionsRepository;
use Laas\Database\Repositories\RbacRepository;
use Laas\Database\Repositories\RolesRepository;
use RuntimeException;
use Throwable;

class RbacService implements RbacServiceInterface
{
    private ?RbacRepository $rbac = null;
    private ?RolesRepository $roles = null;
    private ?PermissionsRepository $permissions = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    public function userHasPermission(int $userId, string $permission): bool
    {
        if ($userId <= 0 || trim($permission) === '') {
            return false;
        }

        try {
            return $this->rbacRepository()->userHasPermission($userId, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    public function userHasAnyPermission(int $userId, array $permissions): bool
    {
        if ($userId <= 0 || $permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!is_string($permission) || $permission === '') {
                continue;
            }
            if ($this->userHasPermission($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function userHasRole(int $userId, string $roleName): bool
    {
        if ($userId <= 0 || trim($roleName) === '') {
            return false;
        }

        try {
            return $this->rbacRepository()->userHasRole($userId, $roleName);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function listRoles(): array
    {
        return $this->rolesRepository()->listAll();
    }

    /** @return array<string, mixed>|null */
    public function findRole(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Role id must be positive.');
        }

        return $this->rolesRepository()->findById($id);
    }

    public function findRoleIdByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return $this->rolesRepository()->findIdByName($name);
    }

    /** @mutation */
    public function createRole(string $name, ?string $title = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Role name is required.');
        }

        return $this->rolesRepository()->create($name, $title);
    }

    /** @mutation */
    public function updateRole(int $id, string $name, ?string $title = null): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Role id must be positive.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Role name is required.');
        }

        $this->rolesRepository()->update($id, $name, $title);
    }

    /** @mutation */
    public function deleteRole(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Role id must be positive.');
        }

        $this->rolesRepository()->delete($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function listPermissions(): array
    {
        return $this->permissionsRepository()->listAll();
    }

    /** @return array<int, string> */
    public function listRolePermissions(int $roleId): array
    {
        if ($roleId <= 0) {
            throw new InvalidArgumentException('Role id must be positive.');
        }

        return $this->rbacRepository()->listRolePermissions($roleId);
    }

    /**
     * @param array<int, int> $permissionIds
     * @return array{added: array<int, int>, removed: array<int, int>}
     * @mutation
     */
    public function setRolePermissions(int $roleId, array $permissionIds): array
    {
        if ($roleId <= 0) {
            throw new InvalidArgumentException('Role id must be positive.');
        }

        return $this->rbacRepository()->setRolePermissions($roleId, $permissionIds);
    }

    /** @return array<int, int> */
    public function resolvePermissionIdsByName(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $names))));
        if ($names === []) {
            return [];
        }

        $map = [];
        foreach ($this->permissionsRepository()->listAll() as $perm) {
            $name = (string) ($perm['name'] ?? '');
            $id = (int) ($perm['id'] ?? 0);
            if ($name !== '' && $id > 0) {
                $map[$name] = $id;
            }
        }

        $ids = [];
        foreach ($names as $name) {
            if (isset($map[$name])) {
                $ids[] = $map[$name];
            }
        }

        return $ids;
    }

    /** @return array<int, string> */
    public function resolvePermissionNamesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach ($this->permissionsRepository()->listAll() as $perm) {
            $name = (string) ($perm['name'] ?? '');
            $id = (int) ($perm['id'] ?? 0);
            if ($name !== '' && $id > 0) {
                $map[$id] = $name;
            }
        }

        $names = [];
        foreach ($ids as $id) {
            if (isset($map[$id])) {
                $names[] = $map[$id];
            }
        }

        return $names;
    }

    private function rbacRepository(): RbacRepository
    {
        if ($this->rbac !== null) {
            return $this->rbac;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->rbac = new RbacRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->rbac;
    }

    private function rolesRepository(): RolesRepository
    {
        if ($this->roles !== null) {
            return $this->roles;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->roles = new RolesRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->roles;
    }

    private function permissionsRepository(): PermissionsRepository
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->permissions = new PermissionsRepository($this->db->pdo());
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->permissions;
    }
}
