<?php
declare(strict_types=1);

namespace Laas\Support;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use Throwable;

final class AuditLogger
{
    public function __construct(private ?DatabaseManager $db = null)
    {
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
        if ($this->db === null || !$this->db->healthCheck()) {
            return;
        }

        try {
            $repo = new AuditLogRepository($this->db);
            $repo->log(
                $action,
                $entity,
                $entityId,
                $context,
                $userId ?? $this->resolveUserId(),
                $ip ?? $this->resolveIp()
            );
        } catch (Throwable) {
            // audit is best-effort
        }
    }

    private function resolveUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $raw = $_SESSION['user_id'] ?? null;
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function resolveIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? $ip : null;
    }
}
