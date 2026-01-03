<?php
declare(strict_types=1);

namespace Laas\Support\Rbac;

use Laas\Database\DatabaseManager;
use PDO;
use Throwable;

final class RbacDiagnosticsService
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    /** @return array<int, string> */
    public function getUserRoles(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.name FROM roles r JOIN role_user ru ON ru.role_id = r.id WHERE ru.user_id = :user_id ORDER BY r.name ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();
        return array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows ?: []);
    }

    /** @return array{groups: array<int, array{key: string, permissions: array<int, array{name: string, title: string|null, roles: array<int, string>}>}>} */
    public function getUserEffectivePermissions(int $userId): array
    {
        $map = $this->permissionRoleMap($userId);

        $permissions = [];
        foreach ($map as $name => $row) {
            $permissions[] = [
                'name' => $name,
                'title' => $row['title'],
            ];
        }

        $grouper = new PermissionGrouper();
        $grouped = $grouper->group($permissions);
        $groups = [];
        foreach ($grouped as $key => $items) {
            $rows = [];
            foreach ($items as $perm) {
                $name = (string) ($perm['name'] ?? '');
                $title = $perm['title'] ?? null;
                $roles = $map[$name]['roles'] ?? [];
                $rows[] = [
                    'name' => $name,
                    'title' => is_string($title) && $title !== '' ? $title : null,
                    'roles' => $roles,
                    'roles_label' => $this->rolesLabel($roles),
                ];
            }
            $groups[] = [
                'key' => (string) $key,
                'permissions' => $rows,
            ];
        }

        return [
            'groups' => $groups,
        ];
    }

    /** @return array{allowed: bool, roles: array<int, string>} */
    public function explainPermission(int $userId, string $permissionName): array
    {
        $permissionName = trim($permissionName);
        if ($permissionName === '') {
            return ['allowed' => false, 'roles' => []];
        }

        $stmt = $this->pdo->prepare(
            'SELECT r.name FROM roles r
             JOIN role_user ru ON ru.role_id = r.id
             JOIN permission_role pr ON pr.role_id = r.id
             JOIN permissions p ON p.id = pr.permission_id
             WHERE ru.user_id = :user_id AND p.name = :permission
             ORDER BY r.name ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'permission' => $permissionName,
        ]);
        $rows = $stmt->fetchAll();
        $roles = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows ?: [])));

        return [
            'allowed' => $roles !== [],
            'roles' => $roles,
            'roles_label' => $this->rolesLabel($roles),
        ];
    }

    /** @return array<string, array{title: string|null, roles: array<int, string>}> */
    private function permissionRoleMap(int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.name AS perm_name, p.title AS perm_title, r.name AS role_name
                 FROM roles r
                 JOIN role_user ru ON ru.role_id = r.id
                 JOIN permission_role pr ON pr.role_id = r.id
                 JOIN permissions p ON p.id = pr.permission_id
                 WHERE ru.user_id = :user_id
                 ORDER BY p.name ASC, r.name ASC'
            );
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $row) {
            $name = (string) ($row['perm_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $title = $row['perm_title'] ?? null;
            if (!isset($map[$name])) {
                $map[$name] = [
                    'title' => is_string($title) && $title !== '' ? $title : null,
                    'roles' => [],
                ];
            }
            $role = (string) ($row['role_name'] ?? '');
            if ($role !== '' && !in_array($role, $map[$name]['roles'], true)) {
                $map[$name]['roles'][] = $role;
            }
        }

        return $map;
    }

    /** @param array<int, string> $roles */
    private function rolesLabel(array $roles): string
    {
        $roles = array_values(array_filter($roles, static fn(string $role): bool => $role !== ''));
        return implode(', ', $roles);
    }
}
