<?php
declare(strict_types=1);

namespace Laas\Modules\Menu\Repository;

use Laas\Database\DatabaseManager;
use Laas\Support\Search\LikeEscaper;
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

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM menus WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
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

    /** @return array<int, array<string, mixed>> */
    public function searchByQuery(string $query, int $limit, int $offset): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $escaped = LikeEscaper::escape($query);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM menus WHERE name LIKE :q ESCAPE \'\\\' OR title LIKE :q ESCAPE \'\\\' ORDER BY name ASC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('q', '%' . $escaped . '%');
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
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

    public function deleteMenu(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM menus WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
