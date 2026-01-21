<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\Cache\CacheKey;
use Laas\Support\RequestCache;
use PDO;

final class RbacRepository
{
    private RolesRepository $roles;
    private PermissionsRepository $permissions;
    private CacheInterface $cache;
    private int $ttlPermissions;
    private bool $usePermissionsCache;

    public function __construct(private PDO $pdo)
    {
        $this->roles = new RolesRepository($pdo);
        $this->permissions = new PermissionsRepository($pdo);
        $rootPath = dirname(__DIR__, 3);
        $this->cache = CacheFactory::create($rootPath);
        $config = CacheFactory::config($rootPath);
        $this->ttlPermissions = (int) ($config['ttl_permissions'] ?? $config['ttl_default'] ?? 60);
        $this->usePermissionsCache = $this->shouldUsePermissionsCache();
    }

    public function userHasPermission(int $userId, string $permission): bool
    {
        $cacheKey = 'rbac.perm.' . $userId . '.' . $permission;
        $value = RequestCache::remember($cacheKey, function () use ($userId, $permission): bool {
            $roleIds = $this->listUserRoleIds($userId);
            if ($roleIds === []) {
                return false;
            }

            foreach ($roleIds as $roleId) {
                $permissions = $this->listRolePermissions($roleId);
                if (in_array($permission, $permissions, true)) {
                    return true;
                }
            }

            return false;
        });

        return $value === true;
    }

    public function grantRoleToUser(int $userId, string $roleName): void
    {
        $roleId = $this->roles->ensureRole($roleName, null);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO role_user (user_id, role_id) VALUES (:user_id, :role_id)');
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
        $this->cache->delete(CacheKey::permissionsUser($userId));
        $this->cache->set(CacheKey::sessionRbacVersion($userId), time(), 86400);
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
        $this->cache->delete(CacheKey::permissionsUser($userId));
        $this->cache->set(CacheKey::sessionRbacVersion($userId), time(), 86400);
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
        $this->cache->delete(CacheKey::permissionsRole($roleId));
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
        $this->cache->delete(CacheKey::permissionsRole($roleId));
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
        $cacheKey = 'rbac.role.' . $userId . '.' . $roleName;
        $value = RequestCache::remember($cacheKey, function () use ($userId, $roleName): bool {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM roles r JOIN role_user ru ON ru.role_id = r.id WHERE ru.user_id = :user_id AND r.name = :name LIMIT 1'
            );
            $stmt->execute([
                'user_id' => $userId,
                'name' => $roleName,
            ]);
            return (bool) $stmt->fetch();
        });

        return $value === true;
    }

    /** @return array<int, int> */
    private function listUserRoleIds(int $userId): array
    {
        return RequestCache::remember('rbac.user_role_ids.' . $userId, function () use ($userId): array {
            $stmt = $this->pdo->prepare('SELECT role_id FROM role_user WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll();
            if (!is_array($rows)) {
                return [];
            }

            return array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['role_id'] ?? 0), $rows)));
        });
    }

    /** @return array<int, string> */
    public function listUserRoles(int $userId): array
    {
        return $this->getUserRoles($userId);
    }

    /** @return array<int, array<string, mixed>> */
    public function listRoles(): array
    {
        return $this->roles->listAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function listPermissions(): array
    {
        return $this->permissions->listAll();
    }

    /** @return array<int, string> */
    public function listRolePermissions(int $roleId): array
    {
        return RequestCache::remember('rbac.role_permissions.' . $roleId, function () use ($roleId): array {
            if ($this->usePermissionsCache) {
                $cached = $this->cache->get(CacheKey::permissionsRole($roleId));
                if (is_array($cached)) {
                    return array_values(array_filter(array_map(static fn($name): string => (string) $name, $cached)));
                }
            }

            $stmt = $this->pdo->prepare(
                'SELECT p.name FROM permissions p JOIN permission_role pr ON pr.permission_id = p.id WHERE pr.role_id = :role_id'
            );
            $stmt->execute(['role_id' => $roleId]);
            $rows = $stmt->fetchAll();

            if (!is_array($rows)) {
                return [];
            }

            $names = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows)));
            if ($this->usePermissionsCache) {
                $this->cache->set(CacheKey::permissionsRole($roleId), $names, $this->ttlPermissions);
            }
            return $names;
        });
    }

    /** @param array<int, int> $permissionIds */
    public function setRolePermissions(int $roleId, array $permissionIds): array
    {
        $permissionIds = array_values(array_unique(array_filter($permissionIds, static fn($id): bool => is_int($id) && $id > 0)));

        $existingIds = $this->permissions->listRolePermissionIds($roleId);
        $toAdd = array_values(array_diff($permissionIds, $existingIds));
        $toRemove = array_values(array_diff($existingIds, $permissionIds));

        if ($toAdd !== []) {
            $insertSql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT OR IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
                : 'INSERT IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)';
            $stmt = $this->pdo->prepare($insertSql);
            foreach ($toAdd as $permId) {
                $stmt->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }

        if ($toRemove !== []) {
            $stmt = $this->pdo->prepare('DELETE FROM permission_role WHERE role_id = :role_id AND permission_id = :permission_id');
            foreach ($toRemove as $permId) {
                $stmt->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }

        $this->cache->delete(CacheKey::permissionsRole($roleId));

        return [
            'added' => $toAdd,
            'removed' => $toRemove,
        ];
    }

    /** @return array<int, array<int, string>> */
    public function getRolesForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn($id): bool => is_int($id) && $id > 0)));
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT ru.user_id, r.name FROM role_user ru JOIN roles r ON r.id = ru.role_id WHERE ru.user_id IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($userIds);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $name = (string) ($row['name'] ?? '');
            if ($uid <= 0 || $name === '') {
                continue;
            }
            $result[$uid][] = $name;
        }

        return $result;
    }

    private function shouldUsePermissionsCache(): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'sqlite') {
            return true;
        }

        try {
            $stmt = $this->pdo->query('PRAGMA database_list');
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            if (!is_array($rows) || $rows === []) {
                return false;
            }
            $file = (string) ($rows[0]['file'] ?? '');
            return $file !== '' && $file !== ':memory:';
        } catch (\Throwable) {
            return false;
        }
    }
}
