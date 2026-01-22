<?php

declare(strict_types=1);

namespace Laas\Modules\Media\Repository;

use Laas\Database\DatabaseManager;
use Laas\Support\Search\LikeEscaper;
use PDO;

final class MediaRepository
{
    private PDO $pdo;
    private string $driver;
    private bool $hasStatusColumn = false;
    private bool $hasQuarantinePathColumn = false;
    private bool $hasDiskColumn = false;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->hasStatusColumn = $this->columnExists('status');
        $this->hasQuarantinePathColumn = $this->columnExists('quarantine_path');
        $this->hasDiskColumn = $this->columnExists('disk');
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit, int $offset, string $query = ''): array
    {
        $sql = 'SELECT * FROM media_files';
        $params = [];
        $hasWhere = false;

        if ($this->hasStatusColumn) {
            $sql .= ' WHERE status = :status';
            $params['status'] = 'ready';
            $hasWhere = true;
        }

        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $sql .= $hasWhere ? ' AND ' : ' WHERE ';
            $sql .= 'original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\'';
            $params['q'] = '%' . $escaped . '%';
            $hasWhere = true;
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
        if ($this->hasStatusColumn) {
            $sql .= ' AND media_files.status = :status';
        }
        $sql .= ' ORDER BY CASE WHEN media_files.original_name LIKE :prefix ESCAPE \'\\\' OR media_files.mime_type LIKE :prefix ESCAPE \'\\\' OR users.username LIKE :prefix ESCAPE \'\\\' THEN 0 ELSE 1 END, media_files.id DESC';
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->bindValue('contains', $contains);
        $stmt->bindValue('prefix', $prefix);
        if ($this->hasStatusColumn) {
            $stmt->bindValue('status', 'ready');
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(string $query = ''): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM media_files';
        $params = [];
        $hasWhere = false;

        if ($this->hasStatusColumn) {
            $sql .= ' WHERE status = :status';
            $params['status'] = 'ready';
            $hasWhere = true;
        }

        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $sql .= $hasWhere ? ' AND ' : ' WHERE ';
            $sql .= 'original_name LIKE :q ESCAPE \'\\\' OR mime_type LIKE :q ESCAPE \'\\\'';
            $params['q'] = '%' . $escaped . '%';
            $hasWhere = true;
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
        if ($this->hasStatusColumn) {
            $sql .= ' AND status = :status';
            $params['status'] = 'ready';
        }

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
        if ($this->hasStatusColumn) {
            $sql .= ' AND status = :status';
            $params['status'] = 'ready';
        }

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
        $sql = 'SELECT COUNT(*) AS cnt FROM media_files LEFT JOIN users ON users.id = media_files.uploaded_by';
        $sql .= ' WHERE (media_files.original_name LIKE :q ESCAPE \'\\\' OR media_files.mime_type LIKE :q ESCAPE \'\\\' OR users.username LIKE :q ESCAPE \'\\\')';
        if ($this->hasStatusColumn) {
            $sql .= ' AND media_files.status = :status';
        }
        $stmt = $this->pdo->prepare($sql);
        $params = ['q' => $contains];
        if ($this->hasStatusColumn) {
            $params['status'] = 'ready';
        }
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT * FROM media_files WHERE id = :id';
        $params = ['id' => $id];
        if ($this->hasStatusColumn) {
            $sql .= ' AND status = :status';
            $params['status'] = 'ready';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySha256(string $sha256): ?array
    {
        $sql = 'SELECT * FROM media_files WHERE sha256 = :sha256';
        $params = ['sha256' => $sha256];
        if ($this->hasStatusColumn) {
            $sql .= ' AND status = :status';
            $params['status'] = 'ready';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findBySha256ForDedupe(string $sha256): ?array
    {
        $sql = 'SELECT * FROM media_files WHERE sha256 = :sha256 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['sha256' => $sha256]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!$this->hasStatusColumn) {
            $row['status'] = 'ready';
        }

        return $row;
    }

    public function hasDiskColumn(): bool
    {
        return $this->hasDiskColumn;
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

        $sql = 'SELECT * FROM media_files';
        if ($this->hasStatusColumn) {
            $sql .= ' WHERE status = :status';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        if ($this->hasStatusColumn) {
            $stmt->bindValue('status', 'ready');
        }
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
        if ($this->hasStatusColumn) {
            $sql .= ' AND status = :status';
        }
        if (!$allowPublic) {
            $sql .= ' AND (is_public IS NULL OR is_public = 0)';
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('cutoff', $cutoff);
        $stmt->bindValue('after_id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        if ($this->hasStatusColumn) {
            $stmt->bindValue('status', 'ready');
        }
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

    public function createUploading(array $data): int
    {
        if (!$this->hasStatusColumn && !$this->hasQuarantinePathColumn) {
            return $this->create($data);
        }

        $now = date('Y-m-d H:i:s');
        $columns = [
            'uuid',
            'disk_path',
            'original_name',
            'mime_type',
            'size_bytes',
            'sha256',
            'uploaded_by',
            'created_at',
            'is_public',
            'public_token',
        ];
        if ($this->hasStatusColumn) {
            $columns[] = 'status';
        }
        if ($this->hasQuarantinePathColumn) {
            $columns[] = 'quarantine_path';
        }

        $params = [
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
        ];
        if ($this->hasStatusColumn) {
            $params['status'] = 'uploading';
        }
        if ($this->hasQuarantinePathColumn) {
            $params['quarantine_path'] = (string) ($data['quarantine_path'] ?? '');
        }

        $placeholders = array_map(static fn (string $name): string => ':' . $name, $columns);
        $sql = 'INSERT INTO media_files (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    public function markReady(int $id): void
    {
        if (!$this->hasStatusColumn) {
            return;
        }

        $sql = 'UPDATE media_files SET status = :status';
        if ($this->hasQuarantinePathColumn) {
            $sql .= ', quarantine_path = NULL';
        }
        $sql .= ' WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'status' => 'ready',
            'id' => $id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listUploadingOlderThan(string $cutoff, int $limit): array
    {
        if (!$this->hasStatusColumn) {
            return [];
        }

        $sql = 'SELECT id, disk_path, quarantine_path, created_at FROM media_files WHERE status = :status AND created_at < :cutoff ORDER BY id ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('status', 'uploading');
        $stmt->bindValue('cutoff', $cutoff);
        if ($limit > 0) {
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{sha256: string, count: int, items: array<int, array{id: int, disk: string, disk_path: string, size_bytes: int, created_at: string, status: string}>}>
     */
    public function listSha256DuplicatesReport(int $limit, ?string $diskFilter = null): array
    {
        $limit = max(1, $limit);
        $diskFilter = is_string($diskFilter) ? trim($diskFilter) : null;
        if ($diskFilter === '') {
            $diskFilter = null;
        }

        $where = "sha256 IS NOT NULL AND sha256 <> ''";
        $params = [];
        if ($this->hasDiskColumn && $diskFilter !== null) {
            $where .= ' AND disk = :disk';
            $params['disk'] = $diskFilter;
        }

        $select = 'SELECT sha256';
        $group = 'sha256';
        if ($this->hasDiskColumn) {
            $select .= ', disk';
            $group .= ', disk';
        }
        $sql = $select . ' , COUNT(*) AS cnt FROM media_files WHERE ' . $where . ' GROUP BY ' . $group . ' HAVING COUNT(*) > 1 ORDER BY cnt DESC, sha256 ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        if ($limit > 0) {
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $groups = $stmt->fetchAll();
        if (!is_array($groups) || $groups === []) {
            return [];
        }

        $itemsColumns = ['id', 'disk_path', 'size_bytes', 'created_at'];
        if ($this->hasStatusColumn) {
            $itemsColumns[] = 'status';
        }
        if ($this->hasDiskColumn) {
            $itemsColumns[] = 'disk';
        }

        $results = [];
        foreach ($groups as $groupRow) {
            $sha256 = (string) ($groupRow['sha256'] ?? '');
            if ($sha256 === '') {
                continue;
            }
            $disk = $this->hasDiskColumn ? (string) ($groupRow['disk'] ?? '') : '';
            $count = (int) ($groupRow['cnt'] ?? 0);

            $itemSql = 'SELECT ' . implode(', ', $itemsColumns) . ' FROM media_files WHERE sha256 = :sha256';
            $itemParams = ['sha256' => $sha256];
            if ($this->hasDiskColumn) {
                $itemSql .= ' AND disk = :disk';
                $itemParams['disk'] = $disk;
            }
            $itemSql .= ' ORDER BY created_at ASC, id ASC';

            $itemStmt = $this->pdo->prepare($itemSql);
            $itemStmt->execute($itemParams);
            $itemsRaw = $itemStmt->fetchAll();
            $itemsRaw = is_array($itemsRaw) ? $itemsRaw : [];

            $items = [];
            foreach ($itemsRaw as $item) {
                $items[] = [
                    'id' => (int) ($item['id'] ?? 0),
                    'disk' => $this->hasDiskColumn ? (string) ($item['disk'] ?? '') : '',
                    'disk_path' => (string) ($item['disk_path'] ?? ''),
                    'size_bytes' => (int) ($item['size_bytes'] ?? 0),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                    'status' => $this->hasStatusColumn ? (string) ($item['status'] ?? '') : 'ready',
                ];
            }

            $results[] = [
                'sha256' => $sha256,
                'count' => $count,
                'items' => $items,
            ];
        }

        return $results;
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
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

    private function columnExists(string $column): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info('media_files')");
                $rows = $stmt !== false ? $stmt->fetchAll() : [];
                foreach ($rows as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
            $stmt->execute([
                'table' => 'media_files',
                'column' => $column,
            ]);
            $count = (int) ($stmt->fetchColumn() ?: 0);
            return $count > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
