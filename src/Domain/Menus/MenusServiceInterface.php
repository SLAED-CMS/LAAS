<?php
declare(strict_types=1);

namespace Laas\Domain\Menus;

interface MenusServiceInterface
{
    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array;

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, int $offset = 0): array;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): int;

    public function delete(int $id): void;

    /** @return array<int, array<string, mixed>> */
    public function loadItems(int $menuId, bool $enabledOnly = false): array;

    /** @return array<int, array<string, mixed>> */
    public function buildTree(array $items): array;

    /** @return array<string, mixed>|null */
    public function findItem(int $id): ?array;

    public function createItem(array $data): int;

    public function updateItem(int $id, array $data): int;

    public function deleteItem(int $id): void;

    public function setItemEnabled(int $id, int $enabled): void;
}
