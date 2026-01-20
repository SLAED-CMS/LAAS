<?php
declare(strict_types=1);

namespace Laas\Domain\Menus;

use Laas\Domain\Support\ReadOnlyProxy;

final class MenusReadServiceProxy extends ReadOnlyProxy implements MenusReadServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, int $offset = 0): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function loadItems(int $menuId, bool $enabledOnly = false): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<int, array<string, mixed>> */
    public function buildTree(array $items): array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    /** @return array<string, mixed>|null */
    public function findItem(int $id): ?array
    {
        return $this->call(__FUNCTION__, func_get_args());
    }
}
