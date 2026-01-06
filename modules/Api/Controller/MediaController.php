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
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\Search\SearchNormalizer;
use Throwable;

final class MediaController
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

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        $canView = $this->canView($request);

        if (!$canView && $mode === 'private') {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $page = ApiPagination::page($request->query('page'));
        $perPage = ApiPagination::perPage($request->query('per_page'));
        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        if (SearchNormalizer::isTooShort($query)) {
            return ApiResponse::error('validation_failed', 'Validation failed', [
                'q' => 'too_short',
            ], 422);
        }

        $cache = new ApiCache();
        $cacheable = !$canView;
        if ($cacheable) {
            $filters = [
                'q' => $query,
            ];
            $cacheKey = $cache->mediaKey($filters, $page, $perPage);
            $cached = $cache->get($cacheKey);
            if (is_array($cached) && isset($cached['items'], $cached['meta'])) {
                return ApiResponse::ok($cached['items'], $cached['meta'], 200, [
                    'Cache-Control' => 'public, max-age=60',
                ]);
            }
        }

        $offset = ($page - 1) * $perPage;
        if ($canView) {
            $rows = $repo->list($perPage, $offset, $query);
            $total = $repo->count($query);
        } else {
            $rows = $repo->listPublic($perPage, $offset, $query);
            $total = $repo->countPublic($query);
        }

        $items = array_map([$this, 'mapMedia'], $rows);
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

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $row = $repo->findById($id);
        if ($row === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        if (!$this->canView($request)) {
            if ($mode === 'private' || empty($row['is_public'])) {
                return ApiResponse::error('not_found', 'Not Found', [], 404);
            }
        }

        return ApiResponse::ok($this->mapMedia($row));
    }

    public function download(Request $request, array $params = []): Response
    {
        $repo = $this->repository();
        if ($repo === null) {
            return ApiResponse::error('service_unavailable', 'Service Unavailable', [], 503);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $row = $repo->findById($id);
        if ($row === null) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        $isPublic = !empty($row['is_public']);

        if ($isPublic && $mode === 'all') {
            $url = $this->publicUrl($row, 'download');
            return new Response('', 302, [
                'Location' => $url,
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        if ($isPublic && $mode === 'signed') {
            $signer = new MediaSignedUrlService($config);
            if ($signer->isEnabled()) {
                $path = $this->publicUrl($row, 'download');
                $signedUrl = $signer->buildSignedUrl($path, $row, 'download');
                if ($signedUrl !== null) {
                    return new Response('', 302, [
                        'Location' => $signedUrl,
                        'Cache-Control' => 'public, max-age=300',
                    ]);
                }
            }
        }

        if (!$this->canView($request)) {
            return ApiResponse::error('forbidden', 'Forbidden', [], 403);
        }

        $storage = new StorageService($this->rootPath());
        if ($storage->isMisconfigured()) {
            return ApiResponse::error('storage_error', 'Storage error', [], 500);
        }

        $diskPath = (string) ($row['disk_path'] ?? '');
        if (!$storage->exists($diskPath)) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $size = (int) ($row['size_bytes'] ?? $storage->size($diskPath));
        $name = $this->safeDownloadName((string) ($row['original_name'] ?? 'file'), $mime);

        $stream = $storage->getStream($diskPath);
        $body = $stream !== false ? stream_get_contents($stream) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($body === false) {
            return ApiResponse::error('not_found', 'Not Found', [], 404);
        }

        return new Response((string) $body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    private function repository(): ?MediaRepository
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return null;
        }

        try {
            return new MediaRepository($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function canView(Request $request): bool
    {
        $user = $request->getAttribute('api.user');
        if (!is_array($user) || $this->db === null) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'media.view');
        } catch (Throwable) {
            return false;
        }
    }

    private function mapMedia(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'original_name' => (string) ($row['original_name'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'is_public' => !empty($row['is_public']),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'download_url' => '/api/v1/media/' . (int) ($row['id'] ?? 0) . '/download',
        ];
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function mediaConfig(): array
    {
        $path = $this->rootPath() . '/config/media.php';
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function publicMode(array $config): string
    {
        $mode = strtolower((string) ($config['public_mode'] ?? 'private'));
        return in_array($mode, ['private', 'all', 'signed'], true) ? $mode : 'private';
    }

    private function publicUrl(array $row, string $purpose): string
    {
        $id = (int) ($row['id'] ?? 0);
        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $originalName = (string) ($row['original_name'] ?? 'file');

        $name = $this->safeDownloadName($originalName, $mime);
        return '/media/' . $id . '/' . $name . '?p=' . rawurlencode($purpose);
    }

    private function safeDownloadName(string $name, string $mime): string
    {
        $ext = (new MimeSniffer())->extensionForMime($mime) ?? 'bin';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = $this->slugify($base);
        if ($base === '') {
            $base = 'file';
        }

        return $this->safeName($base . '.' . $ext);
    }

    private function safeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'file';
        }

        return str_replace(['"', '\\', '/'], '', $name);
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return $value;
    }
}
