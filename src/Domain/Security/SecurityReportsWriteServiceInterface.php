<?php
declare(strict_types=1);

namespace Laas\Domain\Security;

interface SecurityReportsWriteServiceInterface
{
    /** @mutation */
    public function updateStatus(int $id, string $status): bool;

    /** @mutation */
    public function delete(int $id): bool;

    /**
     * @param array<string, mixed> $data
     * @mutation
     */
    public function insert(array $data): void;

    /** @mutation */
    public function prune(int $days): int;
}
