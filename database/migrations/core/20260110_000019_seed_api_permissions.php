<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');

        $apiAccessId = $this->ensurePermission($pdo, 'api.access', 'Access API');
        $apiTokensId = $this->ensurePermission($pdo, 'api.tokens.manage', 'Manage API tokens');
        $usersViewId = $this->ensurePermission($pdo, 'users.view', 'View users');
        $usersManageId = $this->ensurePermission($pdo, 'users.manage', 'Manage users');

        $stmt = $pdo->prepare(
            $this->insertIgnoreKeyword($pdo) . ' INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ([$apiAccessId, $apiTokensId, $usersViewId, $usersManageId] as $permId) {
            $stmt->execute(['role_id' => $roleId, 'permission_id' => $permId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $this->deletePermission($pdo, 'api.access');
        $this->deletePermission($pdo, 'api.tokens.manage');
        $this->deletePermission($pdo, 'users.view');
        $this->deletePermission($pdo, 'users.manage');
    }

    private function ensureRole(\PDO $pdo, string $name, ?string $title = null): int
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)'
        );
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

        $stmt = $pdo->prepare(
            'INSERT INTO permissions (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => $name,
            'title' => $title,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function deletePermission(\PDO $pdo, string $name): void
    {
        $stmt = $pdo->prepare('DELETE FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
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
