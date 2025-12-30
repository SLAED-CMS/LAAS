<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class RbacRepository
{
    private RolesRepository $roles;
    private PermissionsRepository $permissions;

    public function __construct(private PDO $pdo)
    {
        $this->roles = new RolesRepository($pdo);
        $this->permissions = new PermissionsRepository($pdo);
    }

    public function userHasPermission(int $userId, string $permission): bool
    {
        $sql = <<<SQL
SELECT 1
FROM users u
JOIN role_user ru ON ru.user_id = u.id
JOIN roles r ON r.id = ru.role_id
JOIN permission_role pr ON pr.role_id = r.id
JOIN permissions p ON p.id = pr.permission_id
WHERE u.id = :user_id AND p.name = :permission
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'permission' => $permission,
        ]);
        $row = $stmt->fetch();

        return (bool) $row;
    }

    public function grantRoleToUser(int $userId, string $roleName): void
    {
        $roleId = $this->roles->ensureRole($roleName, null);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO role_user (user_id, role_id) VALUES (:user_id, :role_id)');
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    public function revokeRoleFromUser(int $userId, string $roleName): void
    {
        $roleId = $this->roles->findIdByName($roleName);
        if ($roleId === null) {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM role_user WHERE user_id = :user_id AND role_id = :role_id');
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    public function grantPermissionToRole(string $roleName, string $permissionName): void
    {
        $roleId = $this->roles->ensureRole($roleName, null);
        $permId = $this->permissions->ensurePermission($permissionName, null);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)');
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }

    public function revokePermissionFromRole(string $roleName, string $permissionName): void
    {
        $roleId = $this->roles->findIdByName($roleName);
        $permId = $this->permissions->findIdByName($permissionName);
        if ($roleId === null || $permId === null) {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM permission_role WHERE role_id = :role_id AND permission_id = :permission_id');
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }

    public function ensureRole(string $roleName, ?string $title = null): int
    {
        return $this->roles->ensureRole($roleName, $title);
    }

    public function ensurePermission(string $permName, ?string $title = null): int
    {
        return $this->permissions->ensurePermission($permName, $title);
    }

    /** @return array<int, string> */
    public function getUserRoles(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.name FROM roles r JOIN role_user ru ON ru.role_id = r.id WHERE ru.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    public function userHasRole(int $userId, string $roleName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM roles r JOIN role_user ru ON ru.role_id = r.id WHERE ru.user_id = :user_id AND r.name = :name LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $roleName,
        ]);
        return (bool) $stmt->fetch();
    }

    /** @return array<int, string> */
    public function listUserRoles(int $userId): array
    {
        return $this->getUserRoles($userId);
    }
}
