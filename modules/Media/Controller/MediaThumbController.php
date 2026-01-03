<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Database\DatabaseManager;
use Laas\Database\Repositories\RbacRepository;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Throwable;

final class MediaThumbController
{
    public function __construct(
        private ?DatabaseManager $db = null
    ) {
    }

    public function serve(Request $request, array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $variant = isset($params['variant']) ? (string) $params['variant'] : '';
        if ($id <= 0 || $variant === '') {
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

        $service = new MediaThumbnailService(new StorageService($this->rootPath()));
        $resolved = $service->resolveThumbPath($row, $variant, $config);
        if ($resolved === null) {
            return $this->notFound();
        }

        $path = $resolved['absolute_path'];
        if (!is_file($path)) {
            $reason = $service->getThumbReason($row, $variant, $config);
            return $this->notFound($this->devtoolsHeaders(
                $id,
                (string) ($resolved['mime'] ?? ''),
                0,
                'thumb',
                $this->maskDiskPath((string) ($resolved['disk_path'] ?? '')),
                'local',
                0.0,
                false,
                $reason,
                $this->thumbAlgoVersion($config)
            ));
        }

        $readStart = microtime(true);
        $body = file_get_contents($path);
        $readMs = round((microtime(true) - $readStart) * 1000, 2);
        if ($body === false) {
            return $this->notFound($this->devtoolsHeaders(
                $id,
                (string) ($resolved['mime'] ?? ''),
                0,
                'thumb',
                $this->maskDiskPath((string) ($resolved['disk_path'] ?? '')),
                'local',
                0.0,
                false,
                $service->getThumbReason($row, $variant, $config),
                $this->thumbAlgoVersion($config)
            ));
        }

        $size = filesize($path) ?: 0;

        return new Response((string) $body, 200, [
            'Content-Type' => (string) $resolved['mime'],
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => (string) ($resolved['mime'] ?? ''),
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => 'thumb',
            'X-Media-Disk' => $this->maskDiskPath((string) ($resolved['disk_path'] ?? '')),
            'X-Media-Storage' => 'local',
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Thumb-Generated' => '1',
            'X-Media-Thumb-Reason' => '',
            'X-Media-Thumb-Algo' => (string) $this->thumbAlgoVersion($config),
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

    private function thumbAlgoVersion(array $config): int
    {
        $algo = (int) ($config['thumb_algo_version'] ?? 1);
        return max(1, $algo);
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

    private function devtoolsHeaders(
        int $id,
        string $mime,
        int $size,
        string $mode,
        string $disk,
        string $storage,
        float $readMs,
        bool $generated,
        ?string $reason,
        int $algo
    ): array {
        return [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => $mime,
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => $mode,
            'X-Media-Disk' => $disk,
            'X-Media-Storage' => $storage,
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Thumb-Generated' => $generated ? '1' : '0',
            'X-Media-Thumb-Reason' => $reason ?? '',
            'X-Media-Thumb-Algo' => (string) $algo,
        ];
    }

    private function notFound(array $headers = []): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ] + $headers);
    }
}
