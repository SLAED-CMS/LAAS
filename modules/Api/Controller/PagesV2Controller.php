<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Content\Blocks\BlockRegistry;
use Laas\Core\Container\Container;
use Laas\Domain\Media\MediaServiceInterface;
use Laas\Domain\Pages\PagesServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Throwable;
use Laas\View\View;

final class PagesV2Controller
{
    private const CACHE_TTL = 60;

    /** @var array<int, string> */
    private const FIELDS_DEFAULT_LIST = ['id', 'slug', 'title', 'updated_at'];

    /** @var array<int, string> */
    private const FIELDS_DEFAULT_SINGLE = ['id', 'slug', 'title', 'content', 'updated_at'];

    /** @var array<int, string> */
    private const FIELDS_ALLOWED = ['id', 'slug', 'title', 'content', 'status', 'updated_at', 'blocks'];

    /** @var array<int, string> */
    private const INCLUDE_ALLOWED = ['blocks', 'media', 'menu'];

    public function __construct(
        private ?View $view = null,
        private ?PagesServiceInterface $pagesService = null,
        private ?Container $container = null,
        private ?MediaServiceInterface $mediaService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $service = $this->pagesService();
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
            $rows = $service->listPublishedAll();
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $pageIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
        $revisions = $service->findLatestRevisionIds($pageIds);
        $latestRevisionId = $this->maxRevisionId($revisions);

        $includeBlocks = in_array('blocks', $include, true) || in_array('blocks', $fields, true);
        $includeMedia = in_array('media', $include, true);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapPage($row, $fields, $includeBlocks, $includeMedia);
        }

        $etag = $this->buildEtag([
            'pages',
            (string) $latestRevisionId,
            $locale,
            $this->listKey($fields),
            $this->listKey($include),
        ]);

        $headers = [
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'ETag' => $etag,
            'Surrogate-Key' => $this->surrogateKeys('page', $pageIds),
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
        $service = $this->pagesService();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
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
            $row = $service->find($id);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        if ($row === null || !$this->isPublished($row)) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $includeBlocks = in_array('blocks', $include, true) || in_array('blocks', $fields, true);
        $includeMedia = in_array('media', $include, true);

        $revId = $service->findLatestRevisionId($id);
        $etag = $this->buildEtag([
            'page',
            (string) $id,
            (string) $revId,
            $locale,
            $this->listKey($fields),
            $this->listKey($include),
        ]);

        $headers = [
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'ETag' => $etag,
            'Surrogate-Key' => $this->surrogateKeys('page', [$id]),
        ];

        if ($this->matchesEtag($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return ApiResponse::ok([
            'page' => $this->mapPage($row, $fields, $includeBlocks, $includeMedia),
            'locale' => $locale,
        ], [], 200, $headers);
    }

    public function bySlug(Request $request, array $params = []): Response
    {
        $service = $this->pagesService();
        if ($service === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $slug = (string) ($params['slug'] ?? '');
        if ($slug === '') {
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
            $pages = $service->list([
                'slug' => $slug,
                'status' => 'published',
                'limit' => 1,
                'offset' => 0,
            ]);
        } catch (Throwable) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }
        $row = $pages[0] ?? null;
        if ($row === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $includeBlocks = in_array('blocks', $include, true) || in_array('blocks', $fields, true);
        $includeMedia = in_array('media', $include, true);

        $id = (int) ($row['id'] ?? 0);
        $revId = $id > 0 ? $service->findLatestRevisionId($id) : 0;
        $etag = $this->buildEtag([
            'page',
            (string) $id,
            (string) $revId,
            $locale,
            $this->listKey($fields),
            $this->listKey($include),
        ]);

        $headers = [
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
            'ETag' => $etag,
            'Surrogate-Key' => $this->surrogateKeys('page', [$id]),
        ];

        if ($this->matchesEtag($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return ApiResponse::ok([
            'page' => $this->mapPage($row, $fields, $includeBlocks, $includeMedia),
            'locale' => $locale,
        ], [], 200, $headers);
    }

    private function pagesService(): ?PagesServiceInterface
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

    private function mediaService(): ?MediaServiceInterface
    {
        if ($this->mediaService !== null) {
            return $this->mediaService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MediaServiceInterface::class);
                if ($service instanceof MediaServiceInterface) {
                    $this->mediaService = $service;
                    return $this->mediaService;
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
    private function mapPage(array $row, array $fields, bool $includeBlocks, bool $includeMedia): array
    {
        $page = [];
        foreach ($fields as $field) {
            $page[$field] = match ($field) {
                'id' => (int) ($row['id'] ?? 0),
                'slug' => (string) ($row['slug'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                default => $page[$field] ?? null,
            };
        }

        $blocks = [];
        if ($includeBlocks) {
            $pageId = (int) ($row['id'] ?? 0);
            $blocks = $this->loadBlocks($pageId);
            $page['blocks'] = $blocks;
            if ($blocks === [] && $this->compatBlocksLegacyContent()) {
                $content = (string) ($row['content'] ?? '');
                if (trim($content) !== '') {
                    $page['content_html'] = $content;
                }
            }
        }

        if ($includeMedia) {
            $media = $includeBlocks ? $this->loadMediaFromBlocks($blocks) : [];
            $page['media'] = $media;
        }

        return $page;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadBlocks(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        $service = $this->pagesService();
        if ($service === null) {
            return [];
        }

        try {
            $blocks = $service->findLatestBlocks($pageId);
        } catch (Throwable) {
            return [];
        }

        $registry = BlockRegistry::default();
        return $registry->renderJsonBlocks($blocks);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function loadMediaFromBlocks(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type !== 'image') {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $mediaId = $data['media_id'] ?? null;
            if (is_int($mediaId)) {
                $ids[] = $mediaId;
            } elseif (is_string($mediaId) && ctype_digit($mediaId)) {
                $ids[] = (int) $mediaId;
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $service = $this->mediaService();
        if ($service === null) {
            return [];
        }

        $items = [];
        foreach ($ids as $id) {
            try {
                $row = $service->find($id);
            } catch (Throwable) {
                $row = null;
            }
            if ($row === null) {
                continue;
            }
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'original_name' => (string) ($row['original_name'] ?? ''),
                'mime_type' => (string) ($row['mime_type'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'url' => '/media/' . (int) ($row['id'] ?? 0) . '/file',
            ];
        }

        return $items;
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

        $allowed = self::FIELDS_ALLOWED;
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $out = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = strtolower($part);
            if (!in_array($part, $allowed, true)) {
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

    private function isPublished(array $page): bool
    {
        return (string) ($page['status'] ?? '') === 'published';
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
     * @param array<int, int> $revisions
     */
    private function maxRevisionId(array $revisions): int
    {
        $max = 0;
        foreach ($revisions as $value) {
            if ($value > $max) {
                $max = $value;
            }
        }
        return $max;
    }

    private function compatBlocksLegacyContent(): bool
    {
        $config = $this->compatConfig();
        return (bool) ($config['compat_blocks_legacy_content'] ?? false);
    }

    private function compatConfig(): array
    {
        $path = $this->rootPath() . '/config/compat.php';
        if (!is_file($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
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
