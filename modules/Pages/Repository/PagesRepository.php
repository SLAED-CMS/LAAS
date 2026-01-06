<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Repository;

use Laas\Database\DatabaseManager;
use Laas\Support\Search\LikeEscaper;
use PDO;

final class PagesRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM pages WHERE slug = :slug AND status = :status LIMIT 1'
        );
        $stmt->execute([
            'slug' => $slug,
            'status' => 'published',
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM pages ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listPublished(int $limit = 10, int $offset = 0): array
    {
        $sql = 'SELECT id, title, slug, content, updated_at FROM pages WHERE status = :status ORDER BY updated_at DESC, id DESC';
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['status' => 'published']);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listByStatus(?string $status, int $limit, int $offset): array
    {
        $sql = 'SELECT id, title, slug, content, status, updated_at FROM pages';
        $params = [];

        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listForAdmin(
        int $limit = 100,
        int $offset = 0,
        ?string $query = null,
        ?string $status = null
    ): array {
        $sql = 'SELECT id, title, slug, status, updated_at FROM pages';
        $conditions = [];
        $params = [];

        if ($query !== null && $query !== '') {
            $escaped = LikeEscaper::escape($query);
            $conditions[] = '(title LIKE :q_title ESCAPE \'\\\' OR slug LIKE :q_slug ESCAPE \'\\\')';
            $params['q_title'] = '%' . $escaped . '%';
            $params['q_slug'] = '%' . $escaped . '%';
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit, int $offset, ?string $status = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $escaped = LikeEscaper::escape($query);
        $prefix = $escaped . '%';
        $contains = '%' . $escaped . '%';

        $sql = 'SELECT id, title, slug, status, updated_at, content FROM pages WHERE (title LIKE :contains ESCAPE \'\\\' OR slug LIKE :contains ESCAPE \'\\\')';
        $params = [
            'contains' => $contains,
            'prefix' => $prefix,
        ];

        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY CASE WHEN title LIKE :prefix ESCAPE \'\\\' OR slug LIKE :prefix ESCAPE \'\\\' THEN 0 ELSE 1 END, updated_at DESC, id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue('contains', $params['contains']);
        $stmt->bindValue('prefix', $params['prefix']);
        if (isset($params['status'])) {
            $stmt->bindValue('status', $params['status']);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countSearch(string $query, ?string $status = null): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        $escaped = LikeEscaper::escape($query);
        $contains = '%' . $escaped . '%';

        $sql = 'SELECT COUNT(*) AS cnt FROM pages WHERE (title LIKE :contains ESCAPE \'\\\' OR slug LIKE :contains ESCAPE \'\\\')';
        $params = ['contains' => $contains];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function countByStatus(?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM pages';
        $params = [];

        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            'title' => (string) ($data['title'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE pages SET title = :title, slug = :slug, content = :content, status = :status, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'title' => (string) ($data['title'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
            'updated_at' => $now,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE pages SET status = :status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'updated_at' => $now,
        ]);
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        if ($ignoreId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM pages WHERE slug = :slug AND id <> :id LIMIT 1'
            );
            $stmt->execute([
                'slug' => $slug,
                'id' => $ignoreId,
            ]);
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM pages WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
        }

        return (bool) $stmt->fetchColumn();
    }

}
