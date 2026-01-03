<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');

        $permId = $this->ensurePermission($pdo, 'media.public.toggle', 'Toggle media public');

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        $stmt->execute(['role_id' => $roleId, 'permission_id' => $permId]);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'media.public.toggle']);
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
            'INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'title' => $title,
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
            'INSERT INTO permissions (name, title, created_at, updated_at) VALUES (:name, :title, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'title' => $title,
        ]);

        return (int) $pdo->lastInsertId();
    }
};
