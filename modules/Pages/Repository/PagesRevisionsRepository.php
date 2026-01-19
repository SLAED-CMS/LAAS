<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Repository;

use Laas\Database\DatabaseManager;
use PDO;

final class PagesRevisionsRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function createRevision(int $pageId, array $blocks, ?int $createdBy): int
    {
        $now = date('Y-m-d H:i:s');
        $payload = json_encode($blocks, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $payload = '[]';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO pages_revisions (page_id, blocks_json, created_at, created_by)
             VALUES (:page_id, :blocks_json, :created_at, :created_by)'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'blocks_json' => $payload,
            'created_at' => $now,
            'created_by' => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestByPageId(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pages_revisions WHERE page_id = :page_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['page_id' => $pageId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function findLatestBlocksByPageId(int $pageId): ?array
    {
        $row = $this->findLatestByPageId($pageId);
        if ($row === null) {
            return null;
        }
        $raw = (string) ($row['blocks_json'] ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    public function findLatestRevisionIdByPageId(int $pageId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM pages_revisions WHERE page_id = :page_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['page_id' => $pageId]);
        $row = $stmt->fetch();

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @param array<int, int> $pageIds
     * @return array<int, int>
     */
    public function findLatestRevisionIdsByPageIds(array $pageIds): array
    {
        $pageIds = array_values(array_filter($pageIds, static fn(int $id): bool => $id > 0));
        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT page_id, MAX(id) AS id FROM pages_revisions WHERE page_id IN (' . $placeholders . ') GROUP BY page_id'
        );
        $stmt->execute($pageIds);
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $pageId = (int) ($row['page_id'] ?? 0);
            $revId = (int) ($row['id'] ?? 0);
            if ($pageId > 0) {
                $map[$pageId] = $revId;
            }
        }

        return $map;
    }

    public function deleteByPageId(int $pageId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pages_revisions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);
    }
}
