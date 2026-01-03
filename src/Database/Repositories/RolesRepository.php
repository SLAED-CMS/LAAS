<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use PDO;

final class RolesRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findIdByName(string $name): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();

        return $row ? (int) $row['id'] : null;
    }

    public function ensureRole(string $name, ?string $title = null): int
    {
        $id = $this->findIdByName($name);
        if ($id !== null) {
            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, title, created_at, updated_at FROM roles ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, title, created_at, updated_at FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(string $name, ?string $title): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $title): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE roles SET name = :name, title = :title, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'title' => $title,
            'updated_at' => $now,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM roles WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
