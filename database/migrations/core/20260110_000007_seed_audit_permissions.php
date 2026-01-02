<?php
declare(strict_types=1);

use PDO;

return new class {
    public function up(PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');
        $permId = $this->ensurePermission($pdo, 'audit.view', 'View audit log');

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);
    }

    public function down(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => 'admin']);
        $role = $stmt->fetch();
        if ($role) {
            $roleId = (int) $role['id'];
            $pdo->prepare('DELETE FROM permission_role WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        }

        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'audit.view']);
    }

    private function ensureRole(PDO $pdo, string $name, ?string $title = null): int
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

    private function ensurePermission(PDO $pdo, string $name, ?string $title = null): int
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
