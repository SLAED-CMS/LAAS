<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiCache;
use Laas\Api\ApiResponse;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Menu\Repository\MenuItemsRepository;
use Laas\Modules\Menu\Repository\MenusRepository;
use Throwable;

final class MenusController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function show(Request $request, array $params = []): Response
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $name = (string) ($params['name'] ?? '');
        if ($name === '') {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $locale = (string) ($request->query('locale') ?? '');
        $cache = new ApiCache();
        $cacheKey = $cache->menuKey($name, $locale);
        $cached = $cache->get($cacheKey);
        if (is_array($cached) && isset($cached['menu'], $cached['items'])) {
            return ApiResponse::ok($cached, [], 200, [
                'Cache-Control' => 'public, max-age=60',
            ]);
        }

        $menu = $this->menuRepo()?->findMenuByName($name);
        if ($menu === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $items = $this->itemsRepo()?->listItems((int) ($menu['id'] ?? 0), true) ?? [];
        $payload = [
            'menu' => [
                'id' => (int) ($menu['id'] ?? 0),
                'name' => (string) ($menu['name'] ?? ''),
                'title' => (string) ($menu['title'] ?? ''),
            ],
            'items' => array_map([$this, 'mapItem'], $items),
        ];

        $cache->set($cacheKey, $payload);

        return ApiResponse::ok($payload, [], 200, [
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function menuRepo(): ?MenusRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MenusRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function itemsRepo(): ?MenuItemsRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MenuItemsRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function mapItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'is_external' => (int) ($row['is_external'] ?? 0) === 1,
        ];
    }
}
