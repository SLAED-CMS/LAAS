<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Media\MediaReadServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\View\View;
use Throwable;

final class AdminMediaPickerController
{
    public function __construct(
        private View $view,
        private ?MediaReadServiceInterface $mediaService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null,
        private ?StorageService $storageService = null,
        private ?MediaThumbnailService $thumbsService = null
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden($request);
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = trim((string) ($request->query('q') ?? ''));
        try {
            $items = $this->mapRows($service->list(20, 0, $query));
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        if ($request->isHtmx()) {
            return $this->view->render('media/picker_table.html', [
                'items' => $items,
                'q' => $query,
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('media/picker_modal.html', [
            'items' => $items,
            'q' => $query,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function select(Request $request, array $params = []): Response
    {
        if (!$this->canView($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $service = $this->service();
        if ($service === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $raw = $request->post('media_id');
        if ($raw === null || !ctype_digit($raw)) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $id = (int) $raw;
        try {
            $row = $service->find($id);
        } catch (Throwable) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }
        if ($row === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $thumbUrl = $this->thumbUrl($row);
        $originalUrl = $this->publicUrl($row);

        $payload = [
            'media:selected' => [
                'media_id' => $id,
                'thumb_url' => $thumbUrl,
                'original_url' => $originalUrl,
            ],
        ];

        return new Response('', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'HX-Trigger' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function mapRows(array $rows): array
    {
        $storage = $this->storageService();
        $thumbs = $this->thumbsService();
        if ($storage === null || $thumbs === null) {
            return [];
        }
        $config = $this->mediaConfig();

        return array_map(function (array $row) use ($storage, $thumbs, $config): array {
            $mime = (string) ($row['mime_type'] ?? '');
            $originalName = (string) ($row['original_name'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $size = (int) ($row['size_bytes'] ?? 0);
            $isImage = str_starts_with($mime, 'image/');
            $thumbUrl = '';

            $thumb = $thumbs->resolveThumbPath($row, 'sm', $config);
            if ($thumb !== null && $storage->exists((string) ($thumb['disk_path'] ?? ''))) {
                $thumbUrl = '/media/' . $id . '/thumb/sm';
            }

            return [
                'id' => $id,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_display' => $this->formatBytes($size),
                'is_image' => $isImage,
                'badge' => $mime === 'application/pdf'
                    ? $this->view->translate('admin.media.badge_pdf')
                    : $this->view->translate('admin.media.badge_file'),
                'thumb_url' => $thumbUrl,
                'original_url' => $this->publicUrl($row),
            ];
        }, $rows);
    }

    private function service(): ?MediaReadServiceInterface
    {
        if ($this->mediaService !== null) {
            return $this->mediaService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MediaReadServiceInterface::class);
                if ($service instanceof MediaReadServiceInterface) {
                    $this->mediaService = $service;
                    return $this->mediaService;
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

    private function storageService(): ?StorageService
    {
        if ($this->storageService !== null) {
            return $this->storageService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(StorageService::class);
                if ($service instanceof StorageService) {
                    $this->storageService = $service;
                    return $this->storageService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function thumbsService(): ?MediaThumbnailService
    {
        if ($this->thumbsService !== null) {
            return $this->thumbsService;
        }

        if ($this->container !== null) {
            try {
                $service = $this->container->get(MediaThumbnailService::class);
                if ($service instanceof MediaThumbnailService) {
                    $this->thumbsService = $service;
                    return $this->thumbsService;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
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

    private function canView(Request $request): bool
    {
        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        $rbac = $this->rbacService();
        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($userId, 'media.view');
    }

    private function currentUserId(Request $request): ?int
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            return null;
        }

        $raw = $session->get('user_id');
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }
        return null;
    }

    private function rootPath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function publicUrl(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $mime = (string) ($row['mime_type'] ?? '');
        $originalName = (string) ($row['original_name'] ?? '');
        return '/media/' . $id . '/' . $this->safeDownloadName($originalName, $mime);
    }

    private function thumbUrl(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        return $id > 0 ? '/media/' . $id . '/thumb/sm' : '';
    }

    private function safeDownloadName(string $name, string $mime): string
    {
        $ext = (new MimeSniffer())->extensionForMime($mime) ?? 'bin';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = $this->slugify($base);
        if ($base === '') {
            $base = 'file';
        }

        return $base . '.' . $ext;
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    }

    private function forbidden(Request $request): Response
    {
        return ErrorResponse::respondForRequest($request, 'forbidden', [], 403, [], 'admin.media_picker');
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        return ErrorResponse::respondForRequest($request, $code, [], $status, [], 'admin.media_picker');
    }
}
