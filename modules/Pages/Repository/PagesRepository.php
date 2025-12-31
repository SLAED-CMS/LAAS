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

    /** @return array<int, array<string, mixed>> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM pages ORDER BY id ASC');
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'title' => (string) ($data['title'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pages SET title = :title, slug = :slug, content = :content, status = :status, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'title' => (string) ($data['title'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'content' => (string) ($data['content'] ?? ''),
            'status' => (string) ($data['status'] ?? 'draft'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pages WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

}
