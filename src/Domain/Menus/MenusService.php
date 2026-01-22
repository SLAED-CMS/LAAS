<?php

declare(strict_types=1);

namespace Laas\Domain\Menus;

use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Modules\Menu\Repository\MenuItemsRepository;
use Laas\Modules\Menu\Repository\MenusRepository;
use RuntimeException;
use Throwable;

class MenusService implements MenusServiceInterface, MenusReadServiceInterface, MenusWriteServiceInterface
{
    private ?MenusRepository $menus = null;
    private ?MenuItemsRepository $items = null;

    public function __construct(private DatabaseManager $db)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(array $filters = []): array
    {
        $rows = $this->menusRepository()->listMenus();
        return array_map([$this, 'normalizeMenu'], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $query, int $limit = 10, int $offset = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive.');
        }
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be zero or positive.');
        }

        $rows = $this->menusRepository()->searchByQuery($query, $limit, $offset);
        return array_map([$this, 'normalizeMenu'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu id must be positive.');
        }

        $row = $this->menusRepository()->findById($id);
        return $row === null ? null : $this->normalizeMenu($row);
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $row = $this->menusRepository()->findMenuByName($name);
        return $row === null ? null : $this->normalizeMenu($row);
    }

    /** @mutation */
    public function create(array $data): int
    {
        return $this->menusRepository()->saveMenu($data);
    }

    /** @mutation */
    public function update(int $id, array $data): int
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu id must be positive.');
        }

        $payload = $data;
        $payload['id'] = $id;
        return $this->menusRepository()->saveMenu($payload);
    }

    /** @mutation */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu id must be positive.');
        }

        $this->menusRepository()->deleteMenu($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function loadItems(int $menuId, bool $enabledOnly = false): array
    {
        $rows = $this->menuItemsRepository()->listItems($menuId, $enabledOnly);
        return array_map([$this, 'normalizeItem'], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function buildTree(array $items): array
    {
        return $items;
    }

    /** @return array<string, mixed>|null */
    public function findItem(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu item id must be positive.');
        }

        $row = $this->menuItemsRepository()->findById($id);
        return $row === null ? null : $this->normalizeItem($row);
    }

    /** @mutation */
    public function createItem(array $data): int
    {
        return $this->menuItemsRepository()->saveItem($data);
    }

    /** @mutation */
    public function updateItem(int $id, array $data): int
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu item id must be positive.');
        }

        $payload = $data;
        $payload['id'] = $id;
        return $this->menuItemsRepository()->saveItem($payload);
    }

    /** @mutation */
    public function deleteItem(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu item id must be positive.');
        }

        $this->menuItemsRepository()->deleteItem($id);
    }

    /** @mutation */
    public function setItemEnabled(int $id, int $enabled): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Menu item id must be positive.');
        }

        $this->menuItemsRepository()->setEnabled($id, $enabled === 1 ? 1 : 0);
    }

    private function menusRepository(): MenusRepository
    {
        if ($this->menus !== null) {
            return $this->menus;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->menus = new MenusRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->menus;
    }

    private function menuItemsRepository(): MenuItemsRepository
    {
        if ($this->items !== null) {
            return $this->items;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->items = new MenuItemsRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->items;
    }

    /** @return array<string, mixed> */
    private function normalizeMenu(array $menu): array
    {
        $menu['id'] = (int) ($menu['id'] ?? 0);
        $menu['name'] = (string) ($menu['name'] ?? '');
        $menu['title'] = (string) ($menu['title'] ?? '');

        return $menu;
    }

    /** @return array<string, mixed> */
    private function normalizeItem(array $item): array
    {
        $item['id'] = (int) ($item['id'] ?? 0);
        $item['menu_id'] = (int) ($item['menu_id'] ?? 0);
        $item['label'] = (string) ($item['label'] ?? '');
        $item['url'] = (string) ($item['url'] ?? '');
        $item['sort_order'] = (int) ($item['sort_order'] ?? 0);
        $item['enabled'] = !empty($item['enabled']);
        $item['is_external'] = !empty($item['is_external']);

        return $item;
    }
}
