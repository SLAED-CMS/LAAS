<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use Laas\Database\DatabaseManager;
use PDO;

final class AuditLogRepository
{
    private PDO $pdo;

    public function __construct(DatabaseManager $db)
    {
        $this->pdo = $db->pdo();
    }

    /** @param array<string, mixed> $context */
    public function log(
        string $action,
        string $entity,
        ?int $entityId = null,
        array $context = [],
        ?int $userId = null,
        ?string $ip = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, context, ip_address, created_at)
             VALUES (:user_id, :action, :entity, :entity_id, :context, :ip_address, :created_at)'
        );

        $payload = $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if ($payload === false) {
            $payload = null;
        }

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'context' => $payload,
            'ip_address' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, u.username
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM audit_logs');
        $count = $stmt !== false ? $stmt->fetchColumn() : 0;

        return is_numeric($count) ? (int) $count : 0;
    }

    /** @param array{user?: string, action?: string, from?: string, to?: string} $filters */
    public function search(array $filters, int $limit, int $offset): array
    {
        $query = $this->buildSearchQuery($filters);
        $sql = $query['sql'] . ' ORDER BY a.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array{user?: string, action?: string, from?: string, to?: string} $filters */
    public function countSearch(array $filters): int
    {
        $query = $this->buildSearchQuery($filters, true);
        $stmt = $this->pdo->prepare($query['sql']);
        foreach ($query['params'] as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<int, string> */
    public function listActions(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['action'] ?? ''), $rows)));
    }

    /** @return array<int, array{user_id: int, username: string}> */
    public function listUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT DISTINCT u.id AS user_id, u.username
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.user_id IS NOT NULL
             ORDER BY u.username ASC'
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        return is_array($rows) ? $rows : [];
    }

    /** @param array{user?: string, action?: string, from?: string, to?: string} $filters */
    private function buildSearchQuery(array $filters, bool $countOnly = false): array
    {
        $sql = $countOnly
            ? 'SELECT COUNT(*) AS cnt FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id'
            : 'SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id';
        $conditions = [];
        $params = [];

        $user = trim((string) ($filters['user'] ?? ''));
        if ($user !== '') {
            if (ctype_digit($user)) {
                $conditions[] = 'a.user_id = :user_id';
                $params['user_id'] = (int) $user;
            } else {
                $conditions[] = 'u.username = :username';
                $params['username'] = $user;
            }
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $conditions[] = 'a.action = :action';
            $params['action'] = $action;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $conditions[] = 'a.created_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '') {
            $conditions[] = 'a.created_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return [
            'sql' => $sql,
            'params' => $params,
        ];
    }
}
