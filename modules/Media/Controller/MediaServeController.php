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
        $public = (bool) ($config['public'] ?? false);
        if (!$public && !$this->canView()) {
            return new Response('Forbidden', 403, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $storage = new StorageService($this->rootPath());
        $path = $storage->absolutePath((string) ($row['disk_path'] ?? ''));
        if (!is_file($path)) {
            return $this->notFound();
        }

        $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
        $size = (int) ($row['size_bytes'] ?? filesize($path));
        $name = $this->safeDownloadName((string) ($row['original_name'] ?? 'file'), $mime);
        $disposition = $this->contentDisposition($mime);

        $readStart = microtime(true);
        $body = file_get_contents($path);
        $readMs = round((microtime(true) - $readStart) * 1000, 2);
        if ($body === false) {
            return $this->notFound();
        }

        return new Response((string) $body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => $mime,
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => $disposition,
            'X-Media-Disk' => $this->maskDiskPath((string) ($row['disk_path'] ?? '')),
            'X-Media-Storage' => 'local',
            'X-Media-Read-Time' => (string) $readMs,
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
}
