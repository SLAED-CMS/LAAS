<?php
declare(strict_types=1);

namespace Laas\Support\Rbac;

use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Domain\Users\UsersServiceInterface;

final class RbacDiagnosticsService
{
    public function __construct(
        private RbacServiceInterface $rbac,
        private UsersServiceInterface $users
    ) {
    }

    /** @return array<int, string> */
    public function getUserRoles(int $userId): array
    {
        return $this->users->rolesForUser($userId);
    }

    /** @return array{groups: array<int, array{key: string, permissions: array<int, array{name: string, title: string|null, roles: array<int, string>}>}>} */
    public function getUserEffectivePermissions(int $userId): array
    {
        $map = $this->permissionRoleMap($userId);
        $titles = $this->permissionTitles();

        $permissions = [];
        foreach ($map as $name => $row) {
            $permissions[] = [
                'name' => $name,
                'title' => $titles[$name] ?? null,
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

        $roles = [];
        foreach ($this->users->rolesForUser($userId) as $roleName) {
            $roleId = $this->rbac->findRoleIdByName($roleName);
            if ($roleId === null) {
                continue;
            }
            $permissions = $this->rbac->listRolePermissions($roleId);
            if (in_array($permissionName, $permissions, true)) {
                $roles[] = $roleName;
            }
        }

        $roles = array_values(array_unique(array_filter($roles, static fn(string $role): bool => $role !== '')));

        return [
            'allowed' => $roles !== [],
            'roles' => $roles,
            'roles_label' => $this->rolesLabel($roles),
        ];
    }

    /** @return array<string, array{roles: array<int, string>}> */
    private function permissionRoleMap(int $userId): array
    {
        $map = [];
        foreach ($this->users->rolesForUser($userId) as $roleName) {
            $roleId = $this->rbac->findRoleIdByName($roleName);
            if ($roleId === null) {
                continue;
            }
            $permissions = $this->rbac->listRolePermissions($roleId);
            foreach ($permissions as $permissionName) {
                if (!is_string($permissionName) || $permissionName === '') {
                    continue;
                }
                if (!isset($map[$permissionName])) {
                    $map[$permissionName] = [
                        'roles' => [],
                    ];
                }
                if (!in_array($roleName, $map[$permissionName]['roles'], true)) {
                    $map[$permissionName]['roles'][] = $roleName;
                }
            }
        }

        return $map;
    }

    /** @return array<string, string|null> */
    private function permissionTitles(): array
    {
        $titles = [];
        foreach ($this->rbac->listPermissions() as $permission) {
            $name = (string) ($permission['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $title = $permission['title'] ?? null;
            $titles[$name] = is_string($title) && $title !== '' ? $title : null;
        }

        return $titles;
    }

    /** @param array<int, string> $roles */
    private function rolesLabel(array $roles): string
    {
        $roles = array_values(array_filter($roles, static fn(string $role): bool => $role !== ''));
        return implode(', ', $roles);
    }
}
