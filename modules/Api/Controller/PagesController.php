<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiCache;
use Laas\Api\ApiPagination;
use Laas\Api\ApiResponse;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Pages\Repository\PagesRepository;
use Laas\Support\Search\SearchNormalizer;
use Throwable;

final class PagesController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function index(Request $request): Response
    {
        $repo = $this->repository();
        if ($repo === null) {
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
        if ($query !== '') {
            $rows = $repo->search($query, $perPage, $offset, $status);
            $total = $repo->countSearch($query, $status);
        } else {
            $rows = $repo->listByStatus($status === 'all' ? null : $status, $perPage, $offset);
            $total = $repo->countByStatus($status === 'all' ? null : $status);
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
        $repo = $this->repository();
        if ($repo === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $canViewAll = $this->canViewAll($request);

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $page = $repo->findById($id);
        if ($page === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        if (!$this->isPublished($page) && !$canViewAll) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapPage($page));
    }

    public function bySlug(Request $request, array $params = []): Response
    {
        $repo = $this->repository();
        if ($repo === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $canViewAll = $this->canViewAll($request);

        $slug = (string) ($params['slug'] ?? '');
        if ($slug === '') {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        if ($canViewAll) {
            $page = $repo->findBySlug($slug);
        } else {
            $page = $repo->findPublishedBySlug($slug);
        }

        if ($page === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return ApiResponse::ok($this->mapPage($page));
    }

    private function repository(): ?PagesRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new PagesRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function mapPage(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function canViewAll(Request $request): bool
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user)) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $this->db === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'pages.view');
        } catch (Throwable) {
            return false;
        }
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
