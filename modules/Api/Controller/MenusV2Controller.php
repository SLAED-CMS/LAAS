<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Core\Container\Container;
use Laas\Domain\Menus\MenusReadServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Throwable;
use Laas\View\View;

final class MenusV2Controller
{
    private const CACHE_TTL = 60;

    /** @var array<int, string> */
    private const FIELDS_DEFAULT_LIST = ['id', 'name', 'title'];

    /** @var array<int, string> */
    private const FIELDS_DEFAULT_SINGLE = ['id', 'name', 'title'];

    /** @var array<int, string> */
    private const FIELDS_ALLOWED = ['id', 'name', 'title', 'items'];

    /** @var array<int, string> */
    private const INCLUDE_ALLOWED = ['menu', 'blocks', 'media'];

    public function __construct(
        private ?View $view = null,
        private ?MenusReadServiceInterface $menusService = null,
        private ?Container $container = null
    ) {
    }

    public function index(Request $request): Response
    {
        $service = $this->service();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        try {
            $locale = $this->resolveLocale($request);
            $fields = $this->resolveFields($request, self::FIELDS_DEFAULT_LIST);
            $include = $this->resolveInclude($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('invalid_request', 'Invalid request', [
                'param' => $e->getMessage(),
            ], 400);
        }

        try {
            $menus = $service->list();
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $includeItems = in_array('menu', $include, true) || in_array('items', $fields, true);

        $items = [];
        $ids = [];
        $latestItemsUpdated = '';
        foreach ($menus as $menu) {
            $menuId = (int) ($menu['id'] ?? 0);
            $ids[] = $menuId;
            $items[] = $this->mapMenu($menu, $fields, $includeItems, $latestItemsUpdated);
        }

        $latestMenuUpdated = $this->maxUpdatedAt($menus);
        $etag = $this->buildEtag([
            'menus',
            $latestMenuUpdated,
            $includeItems ? $latestItemsUpdated : '',
            $locale,
            $this->listKey($fields),
            $this->listKey($include),
        ]);

        $headers = [
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'ETag' => $etag,
            'Surrogate-Key' => $this->surrogateKeys('menu', $ids),
        ];

        if ($this->matchesEtag($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
            'locale' => $locale,
        ], [], 200, $headers);
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

        try {
            $locale = $this->resolveLocale($request);
            $fields = $this->resolveFields($request, self::FIELDS_DEFAULT_SINGLE);
            $include = $this->resolveInclude($request);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('invalid_request', 'Invalid request', [
                'param' => $e->getMessage(),
            ], 400);
        }

        try {
            $menu = $service->findByName($name);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        if ($menu === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $includeItems = in_array('menu', $include, true) || in_array('items', $fields, true);
        $latestItemsUpdated = '';
        $item = $this->mapMenu($menu, $fields, $includeItems, $latestItemsUpdated);

        $etag = $this->buildEtag([
            'menu',
            (string) ($menu['id'] ?? 0),
            (string) ($menu['updated_at'] ?? ''),
            $includeItems ? $latestItemsUpdated : '',
            $locale,
            $this->listKey($fields),
            $this->listKey($include),
        ]);

        $headers = [
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'ETag' => $etag,
            'Surrogate-Key' => $this->surrogateKeys('menu', [(int) ($menu['id'] ?? 0)]),
        ];

        if ($this->matchesEtag($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return ApiResponse::ok([
            'menu' => $item,
            'locale' => $locale,
        ], [], 200, $headers);
    }

    private function service(): ?MenusReadServiceInterface
    {
        if ($this->menusService !== null) {
            return $this->menusService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MenusReadServiceInterface::class);
                if ($service instanceof MenusReadServiceInterface) {
                    $this->menusService = $service;
                    return $this->menusService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $fields
     */
    private function mapMenu(array $menu, array $fields, bool $includeItems, string &$latestItemsUpdated): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = match ($field) {
                'id' => (int) ($menu['id'] ?? 0),
                'name' => (string) ($menu['name'] ?? ''),
                'title' => (string) ($menu['title'] ?? ''),
                default => $data[$field] ?? null,
            };
        }

        if ($includeItems) {
            $items = [];
            $service = $this->service();
            if ($service !== null) {
                try {
                    $items = $service->loadItems((int) ($menu['id'] ?? 0), true);
                } catch (Throwable) {
                    $items = [];
                }
            }
            $latestItemsUpdated = $this->maxUpdatedAt($items, $latestItemsUpdated);
            $data['items'] = array_map([$this, 'mapItem'], $items);
        }

        return $data;
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

    private function resolveLocale(Request $request): string
    {
        $raw = $request->query('locale');
        $config = $this->appConfig();
        $default = is_string($config['default_locale'] ?? null) ? $config['default_locale'] : 'en';
        $allowed = is_array($config['locales'] ?? null) ? $config['locales'] : [$default];

        if ($raw === null || $raw === '') {
            return (string) $default;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('locale');
        }

        $locale = strtolower(trim($raw));
        if ($locale === '' || !in_array($locale, $allowed, true)) {
            throw new \InvalidArgumentException('locale');
        }

        return $locale;
    }

    /**
     * @param array<int, string> $default
     * @return array<int, string>
     */
    private function resolveFields(Request $request, array $default): array
    {
        $raw = $request->query('fields');
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('fields');
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = strtolower($part);
            if (!in_array($part, self::FIELDS_ALLOWED, true)) {
                continue;
            }
            if (isset($seen[$part])) {
                continue;
            }
            $seen[$part] = true;
            $out[] = $part;
        }

        return $out !== [] ? $out : $default;
    }

    /**
     * @return array<int, string>
     */
    private function resolveInclude(Request $request): array
    {
        $raw = $request->query('include');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_string($raw)) {
            throw new \InvalidArgumentException('include');
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = strtolower($part);
            if (!in_array($part, self::INCLUDE_ALLOWED, true)) {
                continue;
            }
            if (isset($seen[$part])) {
                continue;
            }
            $seen[$part] = true;
            $out[] = $part;
        }

        return $out;
    }

    private function buildEtag(array $parts): string
    {
        $raw = implode('|', $parts);
        return '"' . sha1($raw) . '"';
    }

    private function matchesEtag(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->getHeader('if-none-match');
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }
        return trim($ifNoneMatch) === $etag;
    }

    /**
     * @param array<int, string> $items
     */
    private function listKey(array $items): string
    {
        $items = array_values(array_unique($items));
        sort($items);
        return implode(',', $items);
    }

    /**
     * @param array<int, int> $ids
     */
    private function surrogateKeys(string $prefix, array $ids): string
    {
        $keys = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $keys[] = $prefix . ':' . $id;
            }
        }
        return implode(' ', $keys);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function maxUpdatedAt(array $rows, string $fallback = ''): string
    {
        $latest = $fallback;
        foreach ($rows as $row) {
            $value = (string) ($row['updated_at'] ?? '');
            if ($value !== '' && $value > $latest) {
                $latest = $value;
            }
        }
        return $latest;
    }

    private function appConfig(): array
    {
        $path = $this->rootPath() . '/config/app.php';
        $config = is_file($path) ? require $path : [];
        return is_array($config) ? $config : [];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }
}
