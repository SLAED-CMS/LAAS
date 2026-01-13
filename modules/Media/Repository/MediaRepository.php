<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Repository;

use Laas\Database\DatabaseManager;
use Laas\Support\Search\LikeEscaper;
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
            $escaped = LikeEscaper::escape($query);
            $sql .= ' WHERE original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\'';
            $params['q'] = '%' . $escaped . '%';
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

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit, int $offset): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $escaped = LikeEscaper::escape($query);
        $prefix = $escaped . '%';
        $contains = '%' . $escaped . '%';

        $sql = 'SELECT media_files.* FROM media_files LEFT JOIN users ON users.id = media_files.uploaded_by';
        $sql .= ' WHERE (media_files.original_name LIKE :contains ESCAPE \'\\\' OR media_files.mime_type LIKE :contains ESCAPE \'\\\' OR users.username LIKE :contains ESCAPE \'\\\')';
        $sql .= ' ORDER BY CASE WHEN media_files.original_name LIKE :prefix ESCAPE \'\\\' OR media_files.mime_type LIKE :prefix ESCAPE \'\\\' OR users.username LIKE :prefix ESCAPE \'\\\' THEN 0 ELSE 1 END, media_files.id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue('contains', $contains);
        $stmt->bindValue('prefix', $prefix);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(string $query = ''): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM media_files';
        $params = [];

        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $sql .= ' WHERE original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\'';
            $params['q'] = '%' . $escaped . '%';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public function listPublic(int $limit, int $offset, string $query = ''): array
    {
        $sql = 'SELECT * FROM media_files WHERE is_public = 1';
        $params = [];

        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $sql .= ' AND (original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\')';
            $params['q'] = '%' . $escaped . '%';
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

    public function countPublic(string $query = ''): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM media_files WHERE is_public = 1';
        $params = [];

        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $sql .= ' AND (original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\')';
            $params['q'] = '%' . $escaped . '%';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function countSearch(string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        $escaped = LikeEscaper::escape($query);
        $contains = '%' . $escaped . '%';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM media_files LEFT JOIN users ON users.id = media_files.uploaded_by WHERE (media_files.original_name LIKE :q ESCAPE \'\\\' OR media_files.mime_type LIKE :q ESCAPE \'\\\' OR users.username LIKE :q ESCAPE \'\\\')'
        );
        $stmt->execute(['q' => $contains]);
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

    public function existsByObjectKey(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM media_files WHERE disk_path = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        return $row !== false;
    }

    /** @return array<int, array<string, mixed>> */
    public function listRecent(int $limit, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM media_files ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function listCandidatesForRetention(string $cutoff, int $limit, int $afterId, bool $allowPublic): array
    {
        $limit = max(1, min(500, $limit));
        $afterId = max(0, $afterId);

        $sql = 'SELECT * FROM media_files WHERE created_at < :cutoff AND id > :after_id';
        if (!$allowPublic) {
            $sql .= ' AND (is_public IS NULL OR is_public = 0)';
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('cutoff', $cutoff);
        $stmt->bindValue('after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
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
