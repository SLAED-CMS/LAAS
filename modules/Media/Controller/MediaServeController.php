<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use Laas\View\View;
use Throwable;

final class MediaServeController
{
    public function __construct(
        private ?View $view = null,
        private ?DatabaseManager $db = null
    ) {
    }

    public function serve(Request $request, array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        if ($id <= 0) {
            return $this->notFound();
        }

        $repo = $this->repository();
        if ($repo === null) {
            return $this->notFound();
        }

        $row = $repo->findById($id);
        if ($row === null) {
            return $this->notFound();
        }

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        $isPublic = $this->isPublicRecord($row);
        $accessMode = 'private';
        $signatureValid = false;
        $signatureExp = null;
        $purpose = (string) ($request->query('p') ?? 'view');
        if (!in_array($purpose, ['view', 'download'], true)) {
            $purpose = 'view';
        }

        if ($mode === 'all') {
            $accessMode = 'public';
        } elseif ($mode === 'signed' && $isPublic) {
            if (in_array($purpose, ['view', 'download'], true)) {
                $signer = new MediaSignedUrlService($config);
                $validation = $signer->validate($row, $purpose, $request->query('exp'), $request->query('sig'));
                $signatureValid = (bool) ($validation['valid'] ?? false);
                $signatureExp = $validation['exp'] ?? null;
                if ($signatureValid) {
                    $accessMode = 'signed';
                }
            }
        }

        if ($accessMode === 'private' && !$this->canView()) {
            return new Response('Forbidden', 403, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $storage = new StorageService($this->rootPath());
        if ($storage->isMisconfigured()) {
            return $this->storageError();
        }
        $diskPath = (string) ($row['disk_path'] ?? '');
        if (!$storage->exists($diskPath)) {
            return $this->notFound();
        }

        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $size = (int) ($row['size_bytes'] ?? $storage->size($diskPath));
        $name = $this->safeDownloadName((string) ($row['original_name'] ?? 'file'), $mime);
        $disposition = $purpose === 'download' ? 'attachment' : $this->contentDisposition($mime);

        $readStart = microtime(true);
        $stream = $storage->getStream($diskPath);
        $body = $stream !== false ? stream_get_contents($stream) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        $readMs = round((microtime(true) - $readStart) * 1000, 2);
        if ($body === false) {
            return $this->notFound();
        }

        $stats = $storage->stats();

        $cacheControl = $accessMode === 'public' ? 'public, max-age=86400' : 'private, max-age=0';

        return new Response((string) $body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $cacheControl,
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => $mime,
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => $disposition,
            'X-Media-Disk' => $storage->driverName(),
            'X-Media-Object-Key' => $this->maskDiskPath($diskPath),
            'X-Media-Storage' => $storage->driverName(),
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Access-Mode' => $accessMode,
            'X-Media-Signature-Valid' => $signatureValid ? '1' : '0',
            'X-Media-Signature-Exp' => $signatureExp !== null ? (string) $signatureExp : '',
            'X-Media-S3-Requests' => (string) ($stats['requests'] ?? 0),
            'X-Media-S3-Time' => (string) round((float) ($stats['total_ms'] ?? 0.0), 2),
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

    private function canView(): bool
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
            return $rbac->userHasPermission($userId, 'media.view');
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

    private function isPublicRecord(array $row): bool
    {
        return !empty($row['is_public']);
    }

    private function safeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'file';
        }

        $name = str_replace(['"', '\\', '/'], '', $name);

        return $name;
    }

    private function contentDisposition(string $mime): string
    {
        if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
            return 'inline';
        }

        return 'attachment';
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

    private function maskDiskPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);
        $count = count($parts);
        if ($count <= 2) {
            return $path;
        }

        return $parts[0] . '/.../' . $parts[$count - 1];
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function storageError(): Response
    {
        return new Response('Error', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
