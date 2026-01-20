<?php
declare(strict_types=1);

namespace Laas\Domain\Security;

use Laas\Domain\Support\ReadOnlyProxy;

final class SecurityReportsReadServiceProxy extends ReadOnlyProxy implements SecurityReportsReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function count(array $filters = []): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, int> */
    public function countByStatus(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, int> */
    public function countByType(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
