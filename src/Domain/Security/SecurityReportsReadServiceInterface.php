<?php

declare(strict_types=1);

namespace Laas\Domain\Security;

interface SecurityReportsReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    public function count(array $filters = []): int;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, int> */
    public function countByStatus(array $filters = []): array;

    /** @return array<string, int> */
    public function countByType(array $filters = []): array;
}
