<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Repository;

use Laas\Database\DatabaseManager;
use PDO;

final class MediaRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit, int $offset, string $query = ''): array
    {
        $sql = 'SELECT * FROM media_files';
        $params = [];

        if ($query !== '') {
            $sql .= ' WHERE original_name LIKE :q OR mime_type LIKE :q';
            $params['q'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(string $query = ''): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM media_files';
        $params = [];

        if ($query !== '') {
            $sql .= ' WHERE original_name LIKE :q OR mime_type LIKE :q';
            $params['q'] = '%' . $query . '%';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media_files WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySha256(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media_files WHERE sha256 = :sha256 LIMIT 1');
        $stmt->execute(['sha256' => $sha256]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO media_files (uuid, disk_path, original_name, mime_type, size_bytes, sha256, uploaded_by, created_at, is_public, public_token)
             VALUES (:uuid, :disk_path, :original_name, :mime_type, :size_bytes, :sha256, :uploaded_by, :created_at, :is_public, :public_token)'
        );
        $stmt->execute([
            'uuid' => (string) ($data['uuid'] ?? ''),
            'disk_path' => (string) ($data['disk_path'] ?? ''),
            'original_name' => (string) ($data['original_name'] ?? ''),
            'mime_type' => (string) ($data['mime_type'] ?? ''),
            'size_bytes' => (int) ($data['size_bytes'] ?? 0),
            'sha256' => $data['sha256'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
            'created_at' => $now,
            'is_public' => !empty($data['is_public']) ? 1 : 0,
            'public_token' => $data['public_token'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media_files WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function setPublic(int $id, bool $isPublic, ?string $token): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE media_files SET is_public = :is_public, public_token = :public_token WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_public' => $isPublic ? 1 : 0,
            'public_token' => $token,
        ]);
    }
}
