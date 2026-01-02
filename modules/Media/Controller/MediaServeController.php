<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
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
        $name = $this->safeName((string) ($row['original_name'] ?? 'file'));
        $disposition = str_starts_with($mime, 'image/') ? 'inline' : 'attachment';

        $body = (string) file_get_contents($path);

        return new Response($body, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
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

    private function notFound(): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
