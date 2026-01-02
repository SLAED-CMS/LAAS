<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');

        $viewId = $this->ensurePermission($pdo, 'media.view', 'View media');
        $uploadId = $this->ensurePermission($pdo, 'media.upload', 'Upload media');
        $deleteId = $this->ensurePermission($pdo, 'media.delete', 'Delete media');

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ([$viewId, $uploadId, $deleteId] as $permId) {
            $stmt->execute(['role_id' => $roleId, 'permission_id' => $permId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'media.view']);
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'media.upload']);
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'media.delete']);
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
