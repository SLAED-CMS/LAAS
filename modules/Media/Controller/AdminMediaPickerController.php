<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\View\View;
use Throwable;

final class AdminMediaPickerController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        if (!$this->canView($request)) {
            return $this->forbidden();
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = trim((string) ($request->query('q') ?? ''));
        $items = $this->mapRows($repo->list(20, 0, $query));

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

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $raw = $request->post('media_id');
        if ($raw === null || !ctype_digit($raw)) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $id = (int) $raw;
        $row = $repo->findById($id);
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
        $storage = new StorageService($this->rootPath());
        $thumbs = new MediaThumbnailService($storage);
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
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId($request);
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, 'media.view');
        } catch (Throwable) {
            return false;
        }
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

    private function forbidden(): Response
    {
        return $this->view->render('pages/403.html', [], 403, [], [
            'theme' => 'admin',
        ]);
    }

    private function errorResponse(Request $request, string $code, int $status): Response
    {
        if ($request->isHtmx() || $request->wantsJson()) {
            return ErrorResponse::respond($request, $code, [], $status, [], 'admin.media_picker');
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
