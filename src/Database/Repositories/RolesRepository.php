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

        $stmt = $this->pdo->prepare('INSERT INTO roles (name, title, created_at, updated_at) VALUES (:name, :title, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'title' => $title,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
