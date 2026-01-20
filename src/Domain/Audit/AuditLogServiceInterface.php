<?php
declare(strict_types=1);

namespace Laas\Domain\Audit;

interface AuditLogServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function search(array $filters, int $limit, int $offset): array;

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 50, int $offset = 0): array;

    /** @return array<int, string> */
    public function listActions(): array;

    /** @return array<int, array{user_id: int, username: string}> */
    public function listUsers(): array;

    public function countSearch(array $filters): int;
}
