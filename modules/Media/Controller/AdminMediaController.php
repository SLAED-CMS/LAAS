<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\AuditLogger;
use Laas\View\View;
use Throwable;

final class AdminMediaController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        if (!$this->canView()) {
            return $this->forbidden();
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = trim((string) ($request->query('q') ?? ''));
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 20;

        $total = $repo->count($query);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $items = $this->mapRows($repo->list($perPage, $offset, $query));

        return $this->view->render('pages/media.html', [
            'items' => $items,
            'q' => $query,
            'page' => $page,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => $page > 1 ? $page - 1 : 1,
            'next_page' => $page < $totalPages ? $page + 1 : $totalPages,
            'success' => null,
            'errors' => [],
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function upload(Request $request, array $params = []): Response
    {
        if (!$this->canUpload()) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || empty($file['tmp_name'])) {
            return $this->validationError($request, ['admin.media.error_upload_failed']);
        }

        $config = $this->mediaConfig();
        $maxBytes = (int) ($config['max_bytes'] ?? 0);
        if ($maxBytes > 0 && (int) ($file['size'] ?? 0) > $maxBytes) {
            return $this->validationError($request, ['admin.media.error_too_large']);
        }

        $sniffer = new MimeSniffer();
        $mime = $sniffer->detect((string) $file['tmp_name']);
        if ($mime === null) {
            return $this->validationError($request, ['admin.media.error_invalid_type']);
        }

        $allowed = $config['allowed_mime'] ?? [];
        if (is_array($allowed) && $allowed !== [] && !in_array($mime, $allowed, true)) {
            return $this->validationError($request, ['admin.media.error_invalid_type']);
        }

        $extension = $sniffer->extensionForMime($mime);
        if ($extension === null) {
            return $this->validationError($request, ['admin.media.error_invalid_type']);
        }

        $storage = $this->storage();
        try {
            $stored = $storage->storeUploadedFile($file, $extension);
        } catch (Throwable) {
            return $this->validationError($request, ['admin.media.error_upload_failed']);
        }

        $originalName = $this->safeOriginalName((string) ($file['name'] ?? ''));
        $diskPath = $stored['disk_path'];
        $absolutePath = $stored['absolute_path'];
        $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;

        $mediaId = $repo->create([
            'uuid' => $stored['uuid'],
            'disk_path' => $diskPath,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size_bytes' => (int) ($file['size'] ?? 0),
            'sha256' => $hash,
            'uploaded_by' => $this->currentUserId(),
        ]);

        (new AuditLogger($this->db))->log(
            'media.upload',
            'media_file',
            $mediaId,
            [
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => (int) ($file['size'] ?? 0),
            ],
            $this->currentUserId(),
            $request->ip()
        );

        return $this->tableResponse($request, $repo, $this->view->translate('admin.media.success_uploaded'), []);
    }

    public function delete(Request $request, array $params = []): Response
    {
        if (!$this->canDelete()) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = $this->readId($request);
        if ($id === null) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $row = $repo->findById($id);
        if ($row !== null) {
            $this->storage()->delete((string) ($row['disk_path'] ?? ''));
            $repo->delete($id);
            (new AuditLogger($this->db))->log(
                'media.delete',
                'media_file',
                $id,
                [
                    'original_name' => (string) ($row['original_name'] ?? ''),
                    'mime' => (string) ($row['mime_type'] ?? ''),
                ],
                $this->currentUserId(),
                $request->ip()
            );
        }

        return $this->tableResponse($request, $repo, $this->view->translate('admin.media.success_deleted'), []);
    }

    private function tableResponse(Request $request, MediaRepository $repo, ?string $success, array $errors): Response
    {
        $query = trim((string) ($request->query('q') ?? ''));
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 20;
        $total = $repo->count($query);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $items = $this->mapRows($repo->list($perPage, $offset, $query));

        if ($request->isHtmx()) {
            return $this->view->render('partials/media_table_response.html', [
                'items' => $items,
                'success' => $success,
                'errors' => $errors,
                'page' => $page,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
                'prev_page' => $page > 1 ? $page - 1 : 1,
                'next_page' => $page < $totalPages ? $page + 1 : $totalPages,
                'q' => $query,
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/media.html', [
            'items' => $items,
            'success' => $success,
            'errors' => $errors,
            'q' => $query,
            'page' => $page,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => $page > 1 ? $page - 1 : 1,
            'next_page' => $page < $totalPages ? $page + 1 : $totalPages,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function validationError(Request $request, array $keys): Response
    {
        $errors = [];
        foreach ($keys as $key) {
            $errors[] = $this->view->translate((string) $key);
        }

        if ($request->isHtmx()) {
            return $this->view->render('partials/messages.html', [
                'errors' => $errors,
            ], 422, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        $repo = $this->repository();
        $items = $repo !== null ? $this->mapRows($repo->list(20, 0, '')) : [];
        return $this->view->render('pages/media.html', [
            'items' => $items,
            'success' => null,
            'errors' => $errors,
            'q' => '',
            'page' => 1,
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
            'prev_page' => 1,
            'next_page' => 1,
        ], 422, [], [
            'theme' => 'admin',
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

    private function storage(): StorageService
    {
        return new StorageService($this->rootPath());
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

    private function canView(): bool
    {
        return $this->hasPermission('media.view');
    }

    private function canUpload(): bool
    {
        return $this->hasPermission('media.upload');
    }

    private function canDelete(): bool
    {
        return $this->hasPermission('media.delete');
    }

    private function hasPermission(string $permission): bool
    {
        if ($this->db === null || !$this->db->healthCheck()) {
            return false;
        }

        $userId = $this->currentUserId();
        if ($userId === null) {
            return false;
        }

        try {
            $rbac = new RbacRepository($this->db->pdo());
            return $rbac->userHasPermission($userId, $permission);
        } catch (Throwable) {
            return false;
        }
    }

    private function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $raw = $_SESSION['user_id'] ?? null;
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        return null;
    }

    private function readId(Request $request): ?int
    {
        $raw = $request->post('id');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        return $id > 0 ? $id : null;
    }

    private function safeOriginalName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'file';
        }
        $name = str_replace(['\\', '/'], '', $name);
        return $name;
    }

    private function mapRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $mime = (string) ($row['mime_type'] ?? '');
            $originalName = (string) ($row['original_name'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $size = (int) ($row['size_bytes'] ?? 0);

            return [
                'id' => $id,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'size_display' => $this->formatBytes($size),
                'created_at_display' => (string) ($row['created_at'] ?? ''),
                'is_image' => str_starts_with($mime, 'image/'),
                'url' => '/media/' . $id . '/' . $this->safeDownloadName($originalName, $mime),
            ];
        }, $rows);
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
            return Response::json(['error' => $code], $status);
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
