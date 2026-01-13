<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Api\ApiCacheInvalidator;
use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Contract\ContractResponse;
use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\ClamAvScanner;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\MediaUploadService;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\Security\RateLimiter;
use Laas\Support\AuditLogger;
use Laas\Support\Search\Highlighter;
use Laas\Support\Search\SearchNormalizer;
use Laas\Support\Search\SearchQuery;
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
        if (!$this->canView($request)) {
            if ($request->wantsJson()) {
                return $this->contractForbidden('admin.media.index');
            }
            return $this->forbidden();
        }

        $repo = $this->repository();
        if ($repo === null) {
            if ($request->wantsJson()) {
                return $this->contractServiceUnavailable('admin.media.index');
            }
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 20;

        if (SearchNormalizer::isTooShort($query)) {
            $message = $this->view->translate('search.too_short');
            if ($request->wantsJson()) {
                return $this->contractValidationError('admin.media.index', [
                    'q' => ['too_short'],
                ]);
            }
            if ($request->isHtmx()) {
                $response = $this->view->render('partials/messages.html', [
                    'errors' => [$message],
                ], 422, [], [
                    'theme' => 'admin',
                    'render_partial' => true,
                ]);
                return $response->withHeader('HX-Retarget', '#page-messages');
            }

            return $this->view->render('pages/media.html', [
                'items' => [],
                'q' => $query,
                'page' => 1,
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'prev_page' => 1,
                'next_page' => 1,
                'show_pagination' => 0,
                'success' => null,
                'errors' => [$message],
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $total = $query !== '' ? $repo->countSearch($query) : $repo->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $rows = [];
        if ($query !== '') {
            $search = new SearchQuery($query, $perPage, $page, 'media');
            $rows = $repo->search($search->q, $search->limit, $search->offset);
            $items = $this->mapRows($rows, $this->mediaConfig(), null, $search->q);
        } else {
            $rows = $repo->list($perPage, $offset);
            $items = $this->mapRows($rows, $this->mediaConfig(), null, $query);
        }
        $showPagination = $totalPages > 1 ? 1 : 0;

        $viewData = [
            'items' => $items,
            'q' => $query,
            'page' => $page,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => $page > 1 ? $page - 1 : 1,
            'next_page' => $page < $totalPages ? $page + 1 : $totalPages,
            'show_pagination' => $showPagination,
            'success' => null,
            'errors' => [],
        ];

        if ($request->wantsJson()) {
            $disk = $this->storage()->driverName();
            return ContractResponse::ok([
                'items' => $this->mapContractItems($rows, $disk),
                'counts' => [
                    'total' => $total,
                    'page' => $page,
                    'total_pages' => $totalPages,
                ],
            ], [
                'route' => 'admin.media.index',
            ]);
        }

        if ($request->isHtmx()) {
            return $this->view->render('partials/media_table.html', $viewData, 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->view->render('pages/media.html', $viewData, 200, [], [
            'theme' => 'admin',
        ]);
    }

    public function upload(Request $request, array $params = []): Response
    {
        if (!$this->canUpload($request)) {
            if ($request->wantsJson()) {
                return $this->contractForbidden('admin.media.upload');
            }
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $repo = $this->repository();
        if ($repo === null) {
            if ($request->wantsJson()) {
                return $this->contractServiceUnavailable('admin.media.upload');
            }
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $config = $this->mediaConfig();
        $maxBytes = (int) ($config['max_bytes'] ?? 0);
        $contentLength = $this->contentLength($request);

        if ($contentLength === -1) {
            return $this->uploadMalformedResponse($request);
        }

        if ($maxBytes > 0 && $contentLength !== null && $contentLength > $maxBytes) {
            return $this->uploadTooLargeResponse($request, $this->currentUserId($request), $contentLength, $maxBytes);
        }

        if ($this->isUploadTimedOut()) {
            return $this->uploadTimeoutResponse($request, $this->currentUserId($request), $contentLength, $maxBytes);
        }

        $rateLimited = $this->enforceUploadRateLimit($request);
        if ($rateLimited !== null) {
            return $rateLimited;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            return $this->uploadMalformedResponse($request);
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($maxBytes > 0 && $fileSize > $maxBytes) {
            return $this->uploadTooLargeResponse($request, $this->currentUserId($request), $contentLength, $maxBytes);
        }

        if (empty($file['tmp_name'])) {
            return $this->uploadMalformedResponse($request);
        }

        $originalName = $this->safeOriginalName((string) ($file['name'] ?? ''));

        $scanner = null;
        if (!empty($config['av_enabled'])) {
            $scanner = new ClamAvScanner($config);
        }
        $service = new MediaUploadService(
            $repo,
            $this->storage(),
            new MimeSniffer(),
            $scanner,
            new AuditLogger($this->db, $request->session()),
            $request->ip()
        );
        $result = $service->upload($file, $originalName, $config, $this->currentUserId($request));
        if (($result['status'] ?? '') === 'error') {
            return $this->validationError($request, $result['errors'] ?? []);
        }

        $mediaId = (int) ($result['id'] ?? 0);
        $existing = (bool) ($result['existing'] ?? false);
        $row = $mediaId > 0 ? $repo->findById($mediaId) : null;
        $mime = (string) ($row['mime_type'] ?? '');
        $size = (int) ($row['size_bytes'] ?? 0);
        $hash = (string) ($row['sha256'] ?? '');
        $successKey = $existing ? 'admin.media.success_deduped' : 'admin.media.success_uploaded';

        (new AuditLogger($this->db, $request->session()))->log(
            'media.upload',
            'media_file',
            $mediaId,
            [
                'id' => $mediaId,
                'original_name' => $originalName,
                'mime' => $mime,
                'size' => $size,
            ],
            $this->currentUserId($request),
            $request->ip()
        );

        (new ApiCacheInvalidator())->bumpMedia();

        if ($request->wantsJson()) {
            return ContractResponse::ok([
                'id' => $mediaId,
                'mime' => $mime,
                'size' => $size,
                'hash' => $hash,
                'deduped' => $existing,
            ], [
                'route' => 'admin.media.upload',
                'status' => 'ok',
            ], 201);
        }

        $success = $this->view->translate($successKey);
        return $this->tableResponse($request, $repo, $success, [], $mediaId > 0 ? $mediaId : null);
    }

    public function delete(Request $request, array $params = []): Response
    {
        if (!$this->canDelete($request)) {
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
            try {
                $this->storage()->delete((string) ($row['disk_path'] ?? ''));
            } catch (Throwable) {
                $message = $this->view->translate('storage.s3.delete_failed');
                if ($request->isHtmx()) {
                    return $this->view->render('partials/messages.html', [
                        'errors' => [$message],
                    ], 500, [], [
                        'theme' => 'admin',
                        'render_partial' => true,
                    ]);
                }

                if ($request->wantsJson()) {
                    return ErrorResponse::respond($request, 'storage_error', [], 500, [], 'media.delete');
                }

                return new Response($message, 500, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            }
            $repo->delete($id);
            (new AuditLogger($this->db, $request->session()))->log(
                'media.delete',
                'media_file',
                $id,
                [
                    'id' => $id,
                    'original_name' => (string) ($row['original_name'] ?? ''),
                    'mime' => (string) ($row['mime_type'] ?? ''),
                    'size' => (int) ($row['size_bytes'] ?? 0),
                ],
                $this->currentUserId($request),
                $request->ip()
            );
        }

        (new ApiCacheInvalidator())->bumpMedia();

        $success = $this->view->translate('admin.media.success_deleted');
        if ($request->isHtmx()) {
            return $this->view->render('partials/media_row_deleted.html', [
                'id' => $id,
                'success' => $success,
                'errors' => [],
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->tableResponse($request, $repo, $success, []);
    }

    public function togglePublic(Request $request, array $params = []): Response
    {
        if (!$this->canTogglePublic($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $row = $repo->findById($id);
        if ($row === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $isPublic = !empty($row['is_public']);
        $newPublic = !$isPublic;
        $token = $newPublic ? bin2hex(random_bytes(16)) : null;

        $repo->setPublic($id, $newPublic, $token);

        (new AuditLogger($this->db, $request->session()))->log(
            $newPublic ? 'media.public.enabled' : 'media.public.disabled',
            'media_file',
            $id,
            [
                'id' => $id,
            ],
            $this->currentUserId($request),
            $request->ip()
        );

        (new ApiCacheInvalidator())->bumpMedia();

        $updated = $repo->findById($id);
        $config = $this->mediaConfig();
        $items = $updated !== null ? $this->mapRows([$updated], $config) : [];

        if ($request->isHtmx()) {
            return $this->view->render('partials/media_row_response.html', [
                'item' => $items[0] ?? null,
                'success' => $this->view->translate('media.public.toggled'),
                'errors' => [],
            ], 200, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        return $this->tableResponse($request, $repo, $this->view->translate('media.public.toggled'), []);
    }

    public function signed(Request $request, array $params = []): Response
    {
        if (!$this->canView($request)) {
            return $this->errorResponse($request, 'forbidden', 403);
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->errorResponse($request, 'db_unavailable', 503);
        }

        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->errorResponse($request, 'invalid_request', 400);
        }

        $row = $repo->findById($id);
        if ($row === null) {
            return $this->errorResponse($request, 'not_found', 404);
        }

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        $publicOnly = $request->query('public') === '1';
        $purpose = (string) ($request->query('p') ?? 'view');

        $allowed = ['view', 'download'];
        $variants = $config['thumb_variants'] ?? [];
        if (is_array($variants)) {
            foreach (array_keys($variants) as $variant) {
                if (is_string($variant) && $variant !== '') {
                    $allowed[] = 'thumb:' . $variant;
                }
            }
        }

        if (!in_array($purpose, $allowed, true)) {
            return $this->signedErrorResponse($request, 'media.signed.invalid', 400);
        }

        $url = '';
        $exp = null;
        if ($publicOnly) {
            if ($mode !== 'all') {
                return $this->signedErrorResponse($request, 'media.signed.invalid', 403);
            }
            $url = $this->publicUrl($row, $purpose);
        } else {
            if ($mode !== 'signed' || empty($row['is_public'])) {
                return $this->signedErrorResponse($request, 'media.signed.invalid', 403);
            }
            $signer = new MediaSignedUrlService($config);
            $path = $this->publicUrl($row, $purpose);
            if (!$signer->isEnabled() || $path === '') {
                return $this->signedErrorResponse($request, 'media.signed.invalid', 400);
            }
            $exp = time() + $signer->ttl();
            $url = $signer->buildSignedUrl($path, $row, $purpose, $exp) ?? '';
            if ($url === '') {
                return $this->signedErrorResponse($request, 'media.signed.invalid', 400);
            }
            (new AuditLogger($this->db, $request->session()))->log(
                'media.signed.issued',
                'media_file',
                $id,
                [
                    'id' => $id,
                    'p' => $purpose,
                    'exp' => $exp,
                ],
                $this->currentUserId($request),
                $request->ip()
            );
        }

        return $this->view->render('partials/media_signed_url.html', [
            'url' => $url,
            'expires_at' => $exp,
            'title' => $publicOnly ? $this->view->translate('media.public.on') : $this->view->translate('media.signed.url'),
        ], 200, [], [
            'theme' => 'admin',
            'render_partial' => true,
        ]);
    }

    private function tableResponse(Request $request, MediaRepository $repo, ?string $success, array $errors, ?int $flashId = null): Response
    {
        $query = SearchNormalizer::normalize((string) ($request->query('q') ?? ''));
        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 20;

        if (SearchNormalizer::isTooShort($query)) {
            $message = $this->view->translate('search.too_short');
            if ($request->isHtmx()) {
                $response = $this->view->render('partials/messages.html', [
                    'errors' => [$message],
                ], 422, [], [
                    'theme' => 'admin',
                    'render_partial' => true,
                ]);
                return $response->withHeader('HX-Retarget', '#page-messages');
            }

            return $this->view->render('pages/media.html', [
                'items' => [],
                'q' => $query,
                'page' => 1,
                'total_pages' => 1,
                'has_prev' => false,
                'has_next' => false,
                'prev_page' => 1,
                'next_page' => 1,
                'show_pagination' => 0,
                'success' => null,
                'errors' => [$message],
            ], 422, [], [
                'theme' => 'admin',
            ]);
        }

        $total = $query !== '' ? $repo->countSearch($query) : $repo->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        if ($query !== '') {
            $search = new SearchQuery($query, $perPage, $page, 'media');
            $items = $this->mapRows($repo->search($search->q, $search->limit, $search->offset), $this->mediaConfig(), $flashId, $search->q);
        } else {
            $items = $this->mapRows($repo->list($perPage, $offset), $this->mediaConfig(), $flashId, $query);
        }
        $showPagination = $totalPages > 1 ? 1 : 0;

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
                'show_pagination' => $showPagination,
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
            'show_pagination' => $showPagination,
        ], 200, [], [
            'theme' => 'admin',
        ]);
    }

    private function validationError(Request $request, array $keys): Response
    {
        if ($request->wantsJson()) {
            return $this->uploadContractErrorFromKeys($keys);
        }

        $errors = [];
        foreach ($keys as $key) {
            if (is_array($key)) {
                $k = (string) ($key['key'] ?? '');
                $params = is_array($key['params'] ?? null) ? $key['params'] : [];
                if ($k !== '') {
                    $errors[] = $this->view->translate($k, $params);
                }
                continue;
            }

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
        $items = $repo !== null ? $this->mapRows($repo->list(20, 0, ''), $this->mediaConfig()) : [];
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
            'show_pagination' => 0,
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

    private function canView(Request $request): bool
    {
        return $this->hasPermission($request, 'media.view');
    }

    private function canUpload(Request $request): bool
    {
        return $this->hasPermission($request, 'media.upload');
    }

    private function canDelete(Request $request): bool
    {
        return $this->hasPermission($request, 'media.delete');
    }

    private function canTogglePublic(Request $request): bool
    {
        return $this->hasPermission($request, 'media.public.toggle');
    }

    private function hasPermission(Request $request, string $permission): bool
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
            return $rbac->userHasPermission($userId, $permission);
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

    private function mapRows(array $rows, array $config, ?int $flashId = null, ?string $query = null): array
    {
        $mode = $this->publicMode($config);
        $signedEnabled = !empty($config['signed_urls_enabled']) && !empty($config['signed_url_secret']);

        return array_map(function (array $row) use ($flashId, $mode, $signedEnabled, $query): array {
            $mime = (string) ($row['mime_type'] ?? '');
            $originalName = (string) ($row['original_name'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $size = (int) ($row['size_bytes'] ?? 0);
            $isImage = str_starts_with($mime, 'image/');
            $isPublic = !empty($row['is_public']);
            $url = '/media/' . $id . '/' . $this->safeDownloadName($originalName, $mime);
            $nameSegments = Highlighter::segments($originalName, $query ?? '');
            $mimeSegments = Highlighter::segments($mime, $query ?? '');

            return [
                'id' => $id,
                'original_name' => $originalName,
                'name_segments' => $nameSegments,
                'mime_type' => $mime,
                'mime_segments' => $mimeSegments,
                'size_bytes' => $size,
                'size_display' => $this->formatBytes($size),
                'created_at_display' => (string) ($row['created_at'] ?? ''),
                'is_image' => $isImage,
                'badge' => $mime === 'application/pdf' ? $this->view->translate('admin.media.badge_pdf') : $this->view->translate('admin.media.badge_file'),
                'url' => $url,
                'flash' => $flashId !== null && $id === $flashId,
                'is_public' => $isPublic,
                'public_label' => $isPublic ? $this->view->translate('media.public.on') : $this->view->translate('media.public.off'),
                'public_mode' => $mode,
                'public_mode_all' => $mode === 'all',
                'public_mode_signed' => $mode === 'signed',
                'signed_enabled' => $signedEnabled,
                'public_url' => $url,
            ];
        }, $rows);
    }

    private function enforceUploadRateLimit(Request $request): ?Response
    {
        $config = $this->securityConfig();
        $rateConfig = $config['rate_limit']['media_upload'] ?? ['window' => 300, 'max' => 10];
        $window = (int) ($rateConfig['window'] ?? 300);
        $max = (int) ($rateConfig['max'] ?? 10);

        $ip = $request->ip();
        $userId = $this->currentUserId($request);

        try {
            $limiter = new RateLimiter($this->rootPath());
            $ipResult = $limiter->hit('media_upload_ip', $ip, $window, $max);
            $userResult = null;
            if ($userId !== null) {
                $userResult = $limiter->hit('media_upload_user', 'user:' . $userId, $window, $max);
            }
        } catch (Throwable) {
            return $this->rateLimitResponse($request, $userId);
        }

        if (!$ipResult['allowed'] || ($userResult !== null && !$userResult['allowed'])) {
            return $this->rateLimitResponse($request, $userId);
        }

        return null;
    }

    private function rateLimitResponse(Request $request, ?int $userId): Response
    {
        $message = $this->view->translate('media.rate_limit_exceeded');
        $context = ['ip' => $request->ip()];
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }

        (new AuditLogger($this->db, $request->session()))->log(
            'media.upload.rate_limited',
            'media_upload',
            null,
            $context,
            $userId,
            $request->ip()
        );

        if ($request->isHtmx()) {
            return $this->view->render('partials/messages.html', [
                'errors' => [$message],
            ], 429, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        if ($request->wantsJson()) {
            return ContractResponse::error('rate_limited', [
                'route' => 'admin.media.upload',
            ], 429);
        }

        return new Response($message, 429, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function securityConfig(): array
    {
        $path = $this->rootPath() . '/config/security.php';
        if (!is_file($path)) {
            return [];
        }
        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function contentLength(Request $request): ?int
    {
        $raw = $request->getHeader('content-length') ?? ($_SERVER['CONTENT_LENGTH'] ?? null);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            return -1;
        }
        return (int) $raw;
    }

    private function isUploadTimedOut(): bool
    {
        $maxInput = (int) ini_get('max_input_time');
        $limit = 30;
        if ($maxInput > 0 && $maxInput < $limit) {
            $limit = $maxInput;
        }

        $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? $_SERVER['REQUEST_TIME'] ?? null;
        $started = is_numeric($started) ? (float) $started : microtime(true);
        $elapsed = microtime(true) - $started;

        return $elapsed > $limit;
    }

    private function uploadTooLargeResponse(Request $request, ?int $userId, ?int $contentLength, int $maxBytes): Response
    {
        $message = $this->view->translate('media.upload_too_large', [
            'max' => $this->formatBytes($maxBytes),
        ]);
        $this->auditUploadRejected('media.upload.rejected_size', $request, $userId, $contentLength, $maxBytes);

        return $this->uploadErrorResponse($request, 413, $message);
    }

    private function uploadTimeoutResponse(Request $request, ?int $userId, ?int $contentLength, int $maxBytes): Response
    {
        $message = $this->view->translate('media.upload_timeout');
        $this->auditUploadRejected('media.upload.rejected_timeout', $request, $userId, $contentLength, $maxBytes);

        return $this->uploadErrorResponse($request, 400, $message);
    }

    private function uploadMalformedResponse(Request $request): Response
    {
        $message = $this->view->translate('admin.media.error_upload_failed');
        return $this->uploadErrorResponse($request, 400, $message);
    }

    private function uploadErrorResponse(Request $request, int $status, string $message): Response
    {
        if ($request->isHtmx()) {
            return $this->view->render('partials/messages.html', [
                'errors' => [$message],
            ], $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        if ($request->wantsJson()) {
            $code = $status === 413 ? 'file_too_large' : 'invalid_mime';
            return ContractResponse::error($code, [
                'route' => 'admin.media.upload',
            ], $status);
        }

        return new Response($message, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function auditUploadRejected(string $action, Request $request, ?int $userId, ?int $contentLength, int $maxBytes): void
    {
        (new AuditLogger($this->db, $request->session()))->log(
            $action,
            'media_upload',
            null,
            [
                'ip' => $request->ip(),
                'content_length' => $contentLength,
                'max_bytes' => $maxBytes,
            ],
            $userId,
            $request->ip()
        );
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

    private function publicMode(array $config): string
    {
        $mode = strtolower((string) ($config['public_mode'] ?? 'private'));
        return in_array($mode, ['private', 'all', 'signed'], true) ? $mode : 'private';
    }

    private function publicUrl(array $row, string $purpose): string
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        if (str_starts_with($purpose, 'thumb:')) {
            $variant = substr($purpose, 6);
            if ($variant === '') {
                return '';
            }
            return '/media/' . $id . '/thumb/' . $variant;
        }

        $mime = (string) ($row['mime_type'] ?? '');
        $originalName = (string) ($row['original_name'] ?? '');
        return '/media/' . $id . '/' . $this->safeDownloadName($originalName, $mime);
    }

    private function signedErrorResponse(Request $request, string $key, int $status): Response
    {
        $message = $this->view->translate($key);
        if ($request->isHtmx()) {
            return $this->view->render('partials/messages.html', [
                'errors' => [$message],
            ], $status, [], [
                'theme' => 'admin',
                'render_partial' => true,
            ]);
        }

        if ($request->wantsJson()) {
            return ContractResponse::error('signed_url', [
                'route' => 'admin.media.signed',
            ], $status);
        }

        return new Response($message, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
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
            return ErrorResponse::respond($request, $code, [], $status, [], 'admin.media');
        }

        return new Response('Error', $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function uploadContractErrorFromKeys(array $keys): Response
    {
        $invalidMimeKeys = [
            'admin.media.error_invalid_type',
            'admin.media.error_svg_forbidden',
        ];
        $tooLargeKeys = [
            'media.upload_too_large',
            'media.upload_mime_too_large',
        ];
        $error = 'validation_failed';
        $status = 422;
        foreach ($keys as $key) {
            $value = is_array($key) ? (string) ($key['key'] ?? '') : (string) $key;
            if (in_array($value, $tooLargeKeys, true)) {
                $error = 'file_too_large';
                $status = 413;
                break;
            }
            if (in_array($value, $invalidMimeKeys, true)) {
                $error = 'invalid_mime';
                $status = 400;
            }
        }

        return ContractResponse::error($error, [
            'route' => 'admin.media.upload',
        ], $status, $error === 'validation_failed' ? ['file' => ['invalid']] : []);
    }

    private function contractValidationError(string $route, array $fields = []): Response
    {
        return ContractResponse::error('validation_failed', [
            'route' => $route,
        ], 422, $fields);
    }

    private function contractForbidden(string $route): Response
    {
        return ContractResponse::error(ErrorCode::MEDIA_FORBIDDEN, [
            'route' => $route,
        ], 403);
    }

    private function contractServiceUnavailable(string $route): Response
    {
        return ContractResponse::error('service_unavailable', [
            'route' => $route,
        ], 503);
    }

    private function mapContractItems(array $rows, string $disk): array
    {
        $items = [];
        foreach ($rows as $row) {
            $hash = (string) ($row['sha256'] ?? '');
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['original_name'] ?? ''),
                'mime' => (string) ($row['mime_type'] ?? ''),
                'size' => (int) ($row['size_bytes'] ?? 0),
                'hash' => $hash !== '' ? $hash : null,
                'disk' => $disk,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $items;
    }
}
