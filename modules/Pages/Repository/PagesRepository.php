<?php
declare(strict_types=1);

namespace Laas\Modules\Pages\Repository;

use Laas\Database\DatabaseManager;
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
            $conditions[] = '(title LIKE :q_title OR slug LIKE :q_slug)';
            $params['q_title'] = '%' . $query . '%';
            $params['q_slug'] = '%' . $query . '%';
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
