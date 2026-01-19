<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use Laas\Database\DatabaseManager;
use Laas\Support\Search\LikeEscaper;
use PDO;

final class SecurityReportsRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    public function insert(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO security_reports (type, status, created_at, updated_at, document_uri, violated_directive, blocked_uri, user_agent, ip, request_id, triaged_at, ignored_at)
             VALUES (:type, :status, :created_at, :updated_at, :document_uri, :violated_directive, :blocked_uri, :user_agent, :ip, :request_id, :triaged_at, :ignored_at)'
        );

        $createdAt = (string) ($data['created_at'] ?? date('Y-m-d H:i:s'));
        $stmt->execute([
            'type' => (string) ($data['type'] ?? 'csp'),
            'status' => (string) ($data['status'] ?? 'new'),
            'created_at' => $createdAt,
            'updated_at' => (string) ($data['updated_at'] ?? $createdAt),
            'document_uri' => (string) ($data['document_uri'] ?? ''),
            'violated_directive' => (string) ($data['violated_directive'] ?? ''),
            'blocked_uri' => (string) ($data['blocked_uri'] ?? ''),
            'user_agent' => (string) ($data['user_agent'] ?? ''),
            'ip' => (string) ($data['ip'] ?? ''),
            'request_id' => $data['request_id'] !== null ? (string) $data['request_id'] : null,
            'triaged_at' => $data['triaged_at'] ?? null,
            'ignored_at' => $data['ignored_at'] ?? null,
        ]);
    }

    public function prune(int $days): int
    {
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        $stmt = $this->pdo->prepare('DELETE FROM security_reports WHERE created_at < :cutoff');
        $stmt->bindValue('cutoff', $cutoff);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters, int $limit, int $offset): array
    {
        $query = $this->buildFilters($filters);
        $sql = 'SELECT * FROM security_reports' . $query['where'] . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function count(array $filters): int
    {
        $query = $this->buildFilters($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS cnt FROM security_reports' . $query['where']);
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<string, int> */
    public function countByStatus(array $filters = []): array
    {
        $query = $this->buildFilters($filters);
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS cnt FROM security_reports' . $query['where'] . ' GROUP BY status');
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === '') {
                continue;
            }
            $counts[$status] = (int) ($row['cnt'] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function countByType(array $filters = []): array
    {
        $query = $this->buildFilters($filters);
        $stmt = $this->pdo->prepare('SELECT type, COUNT(*) AS cnt FROM security_reports' . $query['where'] . ' GROUP BY type');
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === '') {
                continue;
            }
            $counts[$type] = (int) ($row['cnt'] ?? 0);
        }

        return $counts;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM security_reports WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $status = in_array($status, ['new', 'triaged', 'ignored'], true) ? $status : 'new';
        $now = date('Y-m-d H:i:s');
        $triagedAt = $status === 'triaged' ? $now : null;
        $ignoredAt = $status === 'ignored' ? $now : null;

        $stmt = $this->pdo->prepare(
            'UPDATE security_reports
             SET status = :status, updated_at = :updated_at, triaged_at = :triaged_at, ignored_at = :ignored_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'status' => $status,
            'updated_at' => $now,
            'triaged_at' => $triagedAt,
            'ignored_at' => $ignoredAt,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM security_reports WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{where: string, params: array<string, mixed>} */
    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        $type = (string) ($filters['type'] ?? '');
        if ($type === 'csp') {
            $conditions[] = 'type = :type';
            $params['type'] = 'csp';
        } elseif ($type === 'other') {
            $conditions[] = 'type <> :type';
            $params['type'] = 'csp';
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['new', 'triaged', 'ignored'], true)) {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        $query = trim((string) ($filters['search'] ?? ''));
        if ($query !== '') {
            $escaped = LikeEscaper::escape($query);
            $like = '%' . $escaped . '%';
            $conditions[] = '(document_uri LIKE :q ESCAPE \'\\\' OR violated_directive LIKE :q ESCAPE \'\\\' OR blocked_uri LIKE :q ESCAPE \'\\\')';
            $params['q'] = $like;
        }

        $since = $filters['since'] ?? null;
        if ($since instanceof \DateTimeInterface) {
            $since = $since->format('Y-m-d H:i:s');
        }
        if (is_string($since)) {
            $since = trim($since);
        } else {
            $since = '';
        }
        if ($since !== '') {
            $conditions[] = 'created_at >= :since';
            $params['since'] = $since;
        }

        $where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

        return [
            'where' => $where,
            'params' => $params,
        ];
    }
}
