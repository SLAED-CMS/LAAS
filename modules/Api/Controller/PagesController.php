<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiCache;
use Laas\Api\ApiPagination;
use Laas\Api\ApiResponse;
use Laas\Core\Container\Container;
use Laas\Domain\Pages\PagesServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\Search\SearchNormalizer;
use Laas\Content\Blocks\BlockRegistry;
use Throwable;
use Laas\View\View;

final class PagesController
{
    public function __construct(
        private ?View $view = null,
        private ?PagesServiceInterface $pagesService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $service = $this->service();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $page = ApiPagination::page($request->query('page'));
        $perPage = ApiPagination::perPage($request->query('per_page'));
        $status = $this->normalizeStatus($request->query('status'));
        $canViewAll = $this->canViewAll($request);

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        if (SearchNormalizer::isTooShort($query)) {
            return ApiResponse::error('validation_failed', 'Validation failed', [
                'q' => 'too_short',
            ], 422);
        }

        if ($status !== 'published' && !$canViewAll) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $cache = new ApiCache();
        $cacheable = $status === 'published' && !$canViewAll;
        if ($cacheable) {
            $filters = [
                'status' => $status,
                'q' => $query,
            ];
            $cacheKey = $cache->pagesKey($filters, $page, $perPage);
            $cached = $cache->get($cacheKey);
            if (is_array($cached) && isset($cached['items'], $cached['meta'])) {
                return ApiResponse::ok($cached['items'], $cached['meta'], 200, [
                    'Cache-Control' => 'public, max-age=60',
                ]);
            }
        }

        $offset = ($page - 1) * $perPage;
        try {
            if ($query !== '') {
                $rows = $service->list([
                    'query' => $query,
                    'status' => $status,
                    'limit' => $perPage,
                    'offset' => $offset,
                ]);
                $total = $service->count([
                    'query' => $query,
                    'status' => $status,
                ]);
            } else {
                $rows = $service->list([
                    'status' => $status,
                    'limit' => $perPage,
                    'offset' => $offset,
                ]);
                $total = $service->count([
                    'status' => $status,
                ]);
            }
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $items = array_map([$this, 'mapPage'], $rows);
        $meta = ApiPagination::meta($page, $perPage, $total);

        if ($cacheable) {
            $cache->set($cacheKey, [
                'items' => $items,
                'meta' => $meta,
            ]);

            return ApiResponse::ok($items, $meta, 200, [
                'Cache-Control' => 'public, max-age=60',
            ]);
        }

        return ApiResponse::ok($items, $meta);
    }

    public function show(Request $request, array $params = []): Response
    {
        $service = $this->service();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $canViewAll = $this->canViewAll($request);

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        try {
            $page = $service->find($id);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        if ($page === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        if (!$this->isPublished($page) && !$canViewAll) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapPage($page, true));
    }

    public function bySlug(Request $request, array $params = []): Response
    {
        $service = $this->service();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $canViewAll = $this->canViewAll($request);

        $slug = (string) ($params['slug'] ?? '');
        if ($slug === '') {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        try {
            $pages = $service->list([
                'slug' => $slug,
                'status' => $canViewAll ? 'all' : 'published',
                'limit' => 1,
                'offset' => 0,
            ]);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $page = $pages[0] ?? null;

        if ($page === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapPage($page, true));
    }

    private function mapPage(array $row, bool $withBlocks = false): array
    {
        $data = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
        if ($withBlocks) {
            $blocks = $this->loadBlocks((int) ($row['id'] ?? 0));
            $data['blocks'] = $blocks;
        }
        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadBlocks(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }
        $service = $this->service();
        if ($service === null) {
            return [];
        }

        try {
            $blocks = $service->findLatestBlocks($pageId);
            if (!is_array($blocks)) {
                return [];
            }
            $registry = BlockRegistry::default();
            return $registry->renderJsonBlocks($blocks);
        } catch (Throwable) {
            return [];
        }
    }

    private function canViewAll(Request $request): bool
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'pages.view');
    }

    private function service(): ?PagesServiceInterface
    {
        if ($this->pagesService !== null) {
            return $this->pagesService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(PagesServiceInterface::class);
                if ($service instanceof PagesServiceInterface) {
                    $this->pagesService = $service;
                    return $this->pagesService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function rbacService(): ?RbacServiceInterface
    {
        if ($this->rbacService !== null) {
            return $this->rbacService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(RbacServiceInterface::class);
                if ($service instanceof RbacServiceInterface) {
                    $this->rbacService = $service;
                    return $this->rbacService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isPublished(array $page): bool
    {
        return (string) ($page['status'] ?? '') === 'published';
    }

    private function normalizeStatus(?string $status): string
    {
        $status = $status ?? 'published';
        $status = strtolower($status);
        if (!in_array($status, ['published', 'draft', 'all'], true)) {
            return 'published';
        }

        return $status;
    }
}
