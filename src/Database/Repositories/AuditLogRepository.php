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
}
