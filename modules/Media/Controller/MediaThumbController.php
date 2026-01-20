<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Controller;

use Laas\Core\Container\Container;
use Laas\Domain\Media\MediaServiceInterface;
use Laas\Domain\Rbac\RbacServiceInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\Media\Service\MediaSignedUrlService;
use Laas\Modules\Media\Service\MediaThumbnailService;
use Laas\Modules\Media\Service\StorageService;
use Laas\View\View;
use Throwable;

final class MediaThumbController
{
    public function __construct(
        private ?View $view = null,
        private ?MediaServiceInterface $mediaService = null,
        private ?Container $container = null,
        private ?RbacServiceInterface $rbacService = null
    ) {
    }

    public function serve(Request $request, array $params = []): Response
    {
        $id = isset($params['id']) ? (int) $params['id'] : 0;
        $variant = isset($params['variant']) ? (string) $params['variant'] : '';
        if ($id <= 0 || $variant === '') {
            return $this->notFound();
        }

        $service = $this->service();
        if ($service === null) {
            return $this->notFound();
        }

        $row = $service->find($id);
        if ($row === null) {
            return $this->notFound();
        }

        $config = $this->mediaConfig();
        $mode = $this->publicMode($config);
        $isPublic = $this->isPublicRecord($row);
        $accessMode = 'private';
        $signatureValid = false;
        $signatureExp = null;
        $purpose = (string) ($request->query('p') ?? '');

        if ($mode === 'all') {
            $accessMode = 'public';
        } elseif ($mode === 'signed' && $isPublic) {
            $expected = 'thumb:' . $variant;
            if ($purpose === $expected) {
                $signer = new MediaSignedUrlService($config);
                $validation = $signer->validate($row, $purpose, $request->query('exp'), $request->query('sig'));
                $signatureValid = (bool) ($validation['valid'] ?? false);
                $signatureExp = $validation['exp'] ?? null;
                if ($signatureValid) {
                    $accessMode = 'signed';
                }
            }
        }

        if ($accessMode === 'private' && !$this->canView($request)) {
            return new Response('Forbidden', 403, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $storage = new StorageService($this->rootPath());
        if ($storage->isMisconfigured()) {
            return $this->storageError();
        }
        $service = new MediaThumbnailService($storage);
        $resolved = $service->resolveThumbPath($row, $variant, $config);
        if ($resolved === null) {
            return $this->notFound();
        }

        $diskPath = (string) ($resolved['disk_path'] ?? '');
        if (!$storage->exists($diskPath)) {
            $reason = $service->getThumbReason($row, $variant, $config);
            return $this->notFound($this->devtoolsHeaders(
                $id,
                (string) ($resolved['mime'] ?? ''),
                0,
                'thumb',
                $storage->driverName(),
                $this->maskDiskPath($diskPath),
                $storage->driverName(),
                0.0,
                false,
                $reason,
                $this->thumbAlgoVersion($config),
                $accessMode,
                $signatureValid,
                $signatureExp
            ));
        }

        $readStart = microtime(true);
        $stream = $storage->getStream($diskPath);
        $body = $stream !== false ? stream_get_contents($stream) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        $readMs = round((microtime(true) - $readStart) * 1000, 2);
        if ($body === false) {
            return $this->notFound($this->devtoolsHeaders(
                $id,
                (string) ($resolved['mime'] ?? ''),
                0,
                'thumb',
                $storage->driverName(),
                $this->maskDiskPath($diskPath),
                $storage->driverName(),
                0.0,
                false,
                $service->getThumbReason($row, $variant, $config),
                $this->thumbAlgoVersion($config),
                $accessMode,
                $signatureValid,
                $signatureExp
            ));
        }

        $size = $storage->size($diskPath);
        $stats = $storage->stats();

        $cacheControl = $accessMode === 'public' ? 'public, max-age=86400' : 'private, max-age=0';

        return new Response((string) $body, 200, [
            'Content-Type' => (string) $resolved['mime'],
            'Content-Length' => (string) $size,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => (string) ($resolved['mime'] ?? ''),
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => 'thumb',
            'X-Media-Disk' => $storage->driverName(),
            'X-Media-Object-Key' => $this->maskDiskPath($diskPath),
            'X-Media-Storage' => $storage->driverName(),
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Access-Mode' => $accessMode,
            'X-Media-Signature-Valid' => $signatureValid ? '1' : '0',
            'X-Media-Signature-Exp' => $signatureExp !== null ? (string) $signatureExp : '',
            'X-Media-Thumb-Generated' => '1',
            'X-Media-Thumb-Reason' => '',
            'X-Media-Thumb-Algo' => (string) $this->thumbAlgoVersion($config),
            'X-Media-S3-Requests' => (string) ($stats['requests'] ?? 0),
            'X-Media-S3-Time' => (string) round((float) ($stats['total_ms'] ?? 0.0), 2),
        ]);
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

    private function service(): ?MediaServiceInterface
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
        string $objectKey,
        string $storage,
        float $readMs,
        bool $generated,
        ?string $reason,
        int $algo,
        string $accessMode,
        bool $signatureValid,
        ?int $signatureExp
    ): array {
        return [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Media-Id' => (string) $id,
            'X-Media-Mime' => $mime,
            'X-Media-Size' => (string) $size,
            'X-Media-Mode' => $mode,
            'X-Media-Disk' => $disk,
            'X-Media-Object-Key' => $objectKey,
            'X-Media-Storage' => $storage,
            'X-Media-Read-Time' => (string) $readMs,
            'X-Media-Access-Mode' => $accessMode,
            'X-Media-Signature-Valid' => $signatureValid ? '1' : '0',
            'X-Media-Signature-Exp' => $signatureExp !== null ? (string) $signatureExp : '',
            'X-Media-Thumb-Generated' => $generated ? '1' : '0',
            'X-Media-Thumb-Reason' => $reason ?? '',
            'X-Media-Thumb-Algo' => (string) $algo,
            'X-Media-S3-Requests' => '0',
            'X-Media-S3-Time' => '0',
        ];
    }

    private function notFound(array $headers = []): Response
    {
        return new Response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ] + $headers);
    }

    private function storageError(): Response
    {
        return new Response('Error', 500, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
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
}
