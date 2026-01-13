<?php
declare(strict_types=1);

namespace Laas\Database\Repositories;

use Laas\Database\DatabaseManager;
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
            'INSERT INTO security_reports (type, created_at, document_uri, violated_directive, blocked_uri, user_agent, ip, request_id)
             VALUES (:type, :created_at, :document_uri, :violated_directive, :blocked_uri, :user_agent, :ip, :request_id)'
        );

        $stmt->execute([
            'type' => (string) ($data['type'] ?? 'csp'),
            'created_at' => (string) ($data['created_at'] ?? date('Y-m-d H:i:s')),
            'document_uri' => (string) ($data['document_uri'] ?? ''),
            'violated_directive' => (string) ($data['violated_directive'] ?? ''),
            'blocked_uri' => (string) ($data['blocked_uri'] ?? ''),
            'user_agent' => (string) ($data['user_agent'] ?? ''),
            'ip' => (string) ($data['ip'] ?? ''),
            'request_id' => $data['request_id'] !== null ? (string) $data['request_id'] : null,
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
}
