<?php
declare(strict_types=1);

namespace Laas\Domain\Pages;

use Laas\Domain\Support\ReadOnlyProxy;

final class PagesReadServiceProxy extends ReadOnlyProxy implements PagesReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function listPublishedAll(): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function count(array $filters = []): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public function findLatestBlocks(int $pageId): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestRevision(int $pageId): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function findLatestRevisionId(int $pageId): int
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, int> */
    public function findLatestRevisionIds(array $pageIds): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
