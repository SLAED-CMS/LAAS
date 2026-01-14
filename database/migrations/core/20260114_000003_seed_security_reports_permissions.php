<?php
declare(strict_types=1);

return new class {
    public function up(\PDO $pdo): void
    {
        $roleId = $this->ensureRole($pdo, 'admin', 'Administrator');

        $viewId = $this->ensurePermission($pdo, 'security_reports.view', 'View security reports');
        $manageId = $this->ensurePermission($pdo, 'security_reports.manage', 'Manage security reports');

        $stmt = $pdo->prepare(
            $this->insertIgnoreKeyword($pdo) . ' INTO permission_role (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ([$viewId, $manageId] as $permId) {
            $stmt->execute(['role_id' => $roleId, 'permission_id' => $permId]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'security_reports.view']);
        $pdo->prepare('DELETE FROM permissions WHERE name = :name')->execute(['name' => 'security_reports.manage']);
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

    private function insertIgnoreKeyword(\PDO $pdo): string
    {
        return $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
};
