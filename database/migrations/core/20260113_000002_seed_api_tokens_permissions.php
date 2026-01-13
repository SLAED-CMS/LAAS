<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');
        $viewId = $this->ensurePermission($pdo, 'api_tokens.view', 'API tokens view');
        $createId = $this->ensurePermission($pdo, 'api_tokens.create', 'API tokens create');
        $revokeId = $this->ensurePermission($pdo, 'api_tokens.revoke', 'API tokens revoke');

        $stmt = $pdo->prepare(
            $this->insertIgnoreKeyword($pdo) . ' INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ([$viewId, $createId, $revokeId] as $permId) {
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
            foreach (['api_tokens.view', 'api_tokens.create', 'api_tokens.revoke'] as $perm) {
                $permId = $this->findPermissionId($pdo, $perm);
                if ($permId === null) {
                    continue;
                }
                $pdo->prepare('DELETE FROM permission_role WHERE role_id = :role_id AND permission_id = :permission_id')->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }

        foreach (['api_tokens.view', 'api_tokens.create', 'api_tokens.revoke'] as $perm) {
            $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => $perm]);
        }
    }

    private function ensureRole(\PDO $pdo, string $name, ?string $title = null): int
    {
        $existing = $this->findRoleId($pdo, $name);
        if ($existing !== null) {
            return $existing;
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
        $existing = $this->findPermissionId($pdo, $name);
        if ($existing !== null) {
            return $existing;
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
        if ($row) {
            return (int) $row['id'];
        }

        return null;
    }

    private function findPermissionId(\PDO $pdo, string $name): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        return null;
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
