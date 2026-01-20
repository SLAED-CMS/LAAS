<?php
declare(strict_types=1);

namespace Laas\Domain\Menus;

interface MenusWriteServiceInterface
{
    /** @mutation */
    public function create(array $data): int;

    /** @mutation */
    public function update(int $id, array $data): int;

    /** @mutation */
    public function delete(int $id): void;

    /** @mutation */
    public function createItem(array $data): int;

    /** @mutation */
    public function updateItem(int $id, array $data): int;

    /** @mutation */
    public function deleteItem(int $id): void;

    /** @mutation */
    public function setItemEnabled(int $id, int $enabled): void;
}
