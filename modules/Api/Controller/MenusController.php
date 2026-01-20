<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiCache;
use Laas\Api\ApiResponse;
use Laas\Core\Container\Container;
use Laas\Domain\Menus\MenusServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Throwable;
use Laas\View\View;

final class MenusController
{
    public function __construct(
        private ?View $view = null,
        private ?MenusServiceInterface $menusService = null,
        private ?Container $container = null
    ) {
    }

    public function show(Request $request, array $params = []): Response
    {
        $service = $this->service();
        if ($service === null) {
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

        try {
            $menu = $service->findByName($name);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        if ($menu === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        try {
            $items = $service->loadItems((int) ($menu['id'] ?? 0), true);
        } catch (Throwable) {
            $items = [];
        }
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

    private function service(): ?MenusServiceInterface
    {
        if ($this->menusService !== null) {
            return $this->menusService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MenusServiceInterface::class);
                if ($service instanceof MenusServiceInterface) {
                    $this->menusService = $service;
                    return $this->menusService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
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
