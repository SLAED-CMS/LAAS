<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

use Laas\Domain\Support\ReadOnlyProxy;

final class MediaReadServiceProxy extends ReadOnlyProxy implements MediaReadServiceInterface
{
    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function list(int $limit = 20, int $offset = 0, string $query = ''): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function count(string $query = ''): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function listPublic(int $limit = 20, int $offset = 0, string $query = ''): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function countPublic(string $query = ''): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, int $offset = 0): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function countSearch(string $query): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
