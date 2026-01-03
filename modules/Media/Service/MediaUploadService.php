<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Support\AuditLogger;
use Throwable;

final class MediaUploadService
{
    public function __construct(
        private MediaRepository $repository,
        private StorageService $storage,
        private MimeSniffer $sniffer,
        private ?AntivirusScannerInterface $scanner = null,
        private ?AuditLogger $auditLogger = null,
        private ?string $requestIp = null
    ) {
    }

    /** @return array{status: string, id?: int, errors?: array<int, array{key: string, params?: array}>, existing?: bool} */
    public function upload(array $file, string $originalName, array $config, ?int $userId): array
    {
        try {
            $quarantine = $this->storage->storeUploadedToQuarantine($file);
        } catch (Throwable) {
            return $this->error('admin.media.error_upload_failed');
        }

        $tmpPath = $quarantine['absolute_path'];
        $maxBytes = (int) ($config['max_bytes'] ?? 0);
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        if ($maxBytes > 0 && $size > $maxBytes) {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('media.upload_too_large', [
                'max' => $this->formatBytes($maxBytes),
            ]);
        }

        $mime = $this->sniffer->detect($tmpPath);
        if ($mime === null) {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('admin.media.error_invalid_type');
        }

        if ($mime === 'image/svg+xml') {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('admin.media.error_svg_forbidden');
        }

        $allowed = $config['allowed_mime'] ?? [];
        if (is_array($allowed) && $allowed !== [] && !in_array($mime, $allowed, true)) {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('admin.media.error_invalid_type');
        }

        $extension = $this->sniffer->extensionForMime($mime);
        if ($extension === null) {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('admin.media.error_invalid_type');
        }

        $maxBytesByMime = $config['max_bytes_by_mime'] ?? [];
        $mimeMax = $maxBytes;
        if (is_array($maxBytesByMime) && isset($maxBytesByMime[$mime]) && is_numeric($maxBytesByMime[$mime])) {
            $mimeMax = (int) $maxBytesByMime[$mime];
        }
        if ($mimeMax > 0 && $size > $mimeMax) {
            $this->storage->deleteAbsolute($tmpPath);
            $this->auditMimeLimitRejected($userId, $mime, $size, $mimeMax);
            return $this->error('media.upload_mime_too_large', [
                'max' => $this->formatBytes($mimeMax),
            ]);
        }

        $hash = hash_file('sha256', $tmpPath);
        if (!is_string($hash) || $hash === '') {
            $this->storage->deleteAbsolute($tmpPath);
            return $this->error('admin.media.error_upload_failed');
        }

        $avEnabled = (bool) ($config['av_enabled'] ?? false);
        if ($avEnabled) {
            if ($this->scanner === null) {
                $this->storage->deleteAbsolute($tmpPath);
                $this->auditVirusRejected($userId, $mime, $size, $hash);
                return $this->error('media.upload_virus_detected');
            }

            $scan = $this->scanner->scan($tmpPath);
            if (($scan['status'] ?? '') !== 'clean') {
                $this->storage->deleteAbsolute($tmpPath);
                $this->auditVirusRejected($userId, $mime, $size, $hash);
                return $this->error('media.upload_virus_detected');
            }
        }

        $existing = $this->repository->findBySha256($hash);
        if ($existing !== null) {
            $this->storage->deleteAbsolute($tmpPath);
            return [
                'status' => 'deduped',
                'id' => (int) ($existing['id'] ?? 0),
                'existing' => true,
            ];
        }

        try {
            $stored = $this->storage->finalizeFromQuarantine($tmpPath, $extension);
        } catch (Throwable $e) {
            $this->storage->deleteAbsolute($tmpPath);
            $key = $this->storageErrorKey($e);
            return $this->error($key);
        }

        try {
            $id = $this->repository->create([
                'uuid' => $stored['uuid'],
                'disk_path' => $stored['disk_path'],
                'original_name' => $originalName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => $hash,
                'uploaded_by' => $userId,
            ]);
        } catch (Throwable) {
            $this->storage->delete($stored['disk_path']);
            return $this->error('admin.media.error_upload_failed');
        }

        if ($id <= 0) {
            $this->storage->delete($stored['disk_path']);
            return $this->error('admin.media.error_upload_failed');
        }

        return [
            'status' => 'stored',
            'id' => $id,
        ];
    }

    /** @return array{status: string, errors: array<int, array{key: string, params?: array}>} */
    private function error(string $key, array $params = []): array
    {
        $payload = ['key' => $key];
        if ($params !== []) {
            $payload['params'] = $params;
        }

        return [
            'status' => 'error',
            'errors' => [$payload],
        ];
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

    private function storageErrorKey(Throwable $e): string
    {
        $message = $e->getMessage();
        return match ($message) {
            's3_misconfigured' => 'storage.s3.misconfigured',
            's3_upload_failed' => 'storage.s3.upload_failed',
            default => 'admin.media.error_upload_failed',
        };
    }

    private function auditVirusRejected(?int $userId, string $mime, int $size, string $hash): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(
            'media.upload.rejected_virus',
            'media_upload',
            null,
            [
                'mime' => $mime,
                'size' => $size,
                'sha256' => $hash,
            ],
            $userId,
            $this->requestIp ?? ''
        );
    }

    private function auditMimeLimitRejected(?int $userId, string $mime, int $size, int $maxBytes): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(
            'media.upload.rejected_mime_size',
            'media_upload',
            null,
            [
                'mime' => $mime,
                'size' => $size,
                'max_bytes' => $maxBytes,
            ],
            $userId,
            $this->requestIp ?? ''
        );
    }
}
