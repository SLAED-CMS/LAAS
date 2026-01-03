<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class PermissionsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findIdByName(string $name): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();

        return $row ? (int) $row['id'] : null;
    }

    public function ensurePermission(string $name, ?string $title = null): int
    {
        $id = $this->findIdByName($name);
        if ($id !== null) {
            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO permissions (name, title, created_at, updated_at) VALUES (:name, :title, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int, array{name: string, title: string|null, id: int}> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, title FROM permissions ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, int> */
    public function listRolePermissionIds(int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission_id FROM permission_role WHERE role_id = :role_id');
        $stmt->execute(['role_id' => $roleId]);
        $rows = $stmt->fetchAll();

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row): int => (int) ($row['permission_id'] ?? 0), $rows);
    }
}
