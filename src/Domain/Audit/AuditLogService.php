<?php
declare(strict_types=1);

namespace Laas\Domain\Audit;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\AuditLogRepository;
use RuntimeException;
use Throwable;

class AuditLogService implements AuditLogServiceInterface
{
    private ?AuditLogRepository $repository = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function search(array $filters, int $limit, int $offset): array
    {
        return $this->repository()->search($filters, $limit, $offset);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 50, int $offset = 0): array
    {
        return $this->repository()->list($limit, $offset);
    }

    /** @return array<int, string> */
    public function listActions(): array
    {
        return $this->repository()->listActions();
    }

    /** @return array<int, array{user_id: int, username: string}> */
    public function listUsers(): array
    {
        return $this->repository()->listUsers();
    }

    public function countSearch(array $filters): int
    {
        return $this->repository()->countSearch($filters);
    }

    private function repository(): AuditLogRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new AuditLogRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }
}
