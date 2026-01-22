<?php

declare(strict_types=1);

namespace Laas\Modules\Menu\Repository;

use Laas\Database\DatabaseManager;
use PDO;

final class MenuItemsRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    /** @return array<int, array<string, mixed>> */
    public function listItems(int $menuId, bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM menu_items WHERE menu_id = :menu_id';
        $params = ['menu_id' => $menuId];

        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM menu_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function saveItem(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $id = isset($data['id']) ? (int) $data['id'] : 0;

        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE menu_items SET label = :label, url = :url, sort_order = :sort_order, enabled = :enabled,
                 is_external = :is_external, updated_at = :updated_at WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'label' => (string) ($data['label'] ?? ''),
                'url' => (string) ($data['url'] ?? ''),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'enabled' => (int) ($data['enabled'] ?? 1),
                'is_external' => (int) ($data['is_external'] ?? 0),
                'updated_at' => $now,
            ]);

            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (menu_id, label, url, sort_order, enabled, is_external, created_at, updated_at)
             VALUES (:menu_id, :label, :url, :sort_order, :enabled, :is_external, :created_at, :updated_at)'
        );
        $stmt->execute([
            'menu_id' => (int) ($data['menu_id'] ?? 0),
            'label' => (string) ($data['label'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'enabled' => (int) ($data['enabled'] ?? 1),
            'is_external' => (int) ($data['is_external'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteItem(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM menu_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function setEnabled(int $id, int $enabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE menu_items SET enabled = :enabled, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<int, int> $orderedIds */
    public function reorderItems(int $menuId, array $orderedIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE menu_items SET sort_order = :sort_order, updated_at = :updated_at
                 WHERE id = :id AND menu_id = :menu_id'
            );
            $now = date('Y-m-d H:i:s');
            $order = 1;
            foreach ($orderedIds as $id) {
                $stmt->execute([
                    'sort_order' => $order,
                    'updated_at' => $now,
                    'id' => (int) $id,
                    'menu_id' => $menuId,
                ]);
                $order++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
