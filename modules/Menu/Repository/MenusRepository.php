<?php
declare(strict_types=1);

namespace Laas\Modules\Menu\Repository;

use Laas\Database\DatabaseManager;
use PDO;

final class MenusRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    public function findMenuByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM menus WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listMenus(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM menus ORDER BY name ASC');
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function saveMenu(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $id = isset($data['id']) ? (int) $data['id'] : 0;

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE menus SET name = :name, title = :title, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'name' => (string) ($data['name'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'updated_at' => $now,
            ]);

            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO menus (name, title, created_at, updated_at) VALUES (:name, :title, :created_at, :updated_at)'
        );
        $stmt->execute([
            'name' => (string) ($data['name'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
