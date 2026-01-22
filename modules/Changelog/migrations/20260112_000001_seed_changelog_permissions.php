<?php

declare(strict_types=1);

return new class () {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');
        $viewId = $this->ensurePermission($pdo, 'changelog.view', 'Changelog view');
        $adminId = $this->ensurePermission($pdo, 'changelog.admin', 'Changelog admin');
        $clearId = $this->ensurePermission($pdo, 'changelog.cache.clear', 'Changelog cache clear');

        $stmt = $pdo->prepare(
            $this->insertIgnoreKeyword($pdo) . ' INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ([$viewId, $adminId, $clearId] as $permId) {
            $stmt->execute([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $roleId = $this->findRoleId($pdo, 'admin');
        if ($roleId !== null) {
            foreach (['changelog.view', 'changelog.admin', 'changelog.cache.clear'] as $perm) {
                $permId = $this->findPermissionId($pdo, $perm);
                if ($permId !== null) {
                    $pdo->prepare('DELETE FROM permission_role WHERE role_id = :role_id AND permission_id = :permission_id')->execute([
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                    ]);
                }
            }
        }

        foreach (['changelog.view', 'changelog.admin', 'changelog.cache.clear'] as $perm) {
            $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => $perm]);
        }
    }

    private function ensureRole(\PDO $pdo, string $name, ?string $title = null): int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        $stmt = $pdo->prepare('INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function ensurePermission(\PDO $pdo, string $name, ?string $title = null): int
    {
        $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        $stmt = $pdo->prepare('INSERT INTO permissions (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function findRoleId(\PDO $pdo, string $name): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function findPermissionId(\PDO $pdo, string $name): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function insertIgnoreKeyword(\PDO $pdo): string
    {
        return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
};
