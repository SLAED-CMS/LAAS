<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

use DateTimeImmutable;
use InvalidArgumentException;
use Laas\Database\DatabaseManager;
use Laas\Modules\Media\Repository\MediaRepository;
use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use PDOException;
use RuntimeException;
use Throwable;

class MediaService
{
    private ?MediaRepository $repository = null;
    private StorageService $storage;
    private MimeSniffer $sniffer;

    public function __construct(
        private DatabaseManager $db,
        private array $config,
        private string $rootPath,
        ?StorageService $storage = null,
        ?MimeSniffer $sniffer = null
    ) {
        $this->storage = $storage ?? new StorageService($rootPath);
        $this->sniffer = $sniffer ?? new MimeSniffer();
    }

    /**
     * @param array{name: string, tmp_path: string, size: int, mime: string} $file
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function upload(array $file, array $options = []): array
    {
        $file = $this->normalizeFile($file);
        $validated = $this->validateFile($file);

        $tmpPath = $file['tmp_path'];
        $sha256 = $this->hashFile($tmpPath);
        $existing = $this->repository()->findBySha256($sha256);
        if ($existing !== null) {
            $this->deleteTemp($tmpPath);
            $existing['existing'] = true;
            return $existing;
        }

        $filename = $this->generateFilename($validated['extension']);
        $diskPath = $filename['disk_path'];

        $this->moveToStorage($file, $diskPath);

        try {
            $mediaId = $this->persistMedia([
                'uuid' => $filename['uuid'],
                'disk_path' => $diskPath,
                'original_name' => $file['name'],
                'mime_type' => $validated['mime'],
                'size_bytes' => $validated['size'],
                'sha256' => $sha256,
                'uploaded_by' => $this->normalizeUserId($options['user_id'] ?? null),
                'is_public' => $options['is_public'] ?? false,
                'public_token' => $options['public_token'] ?? null,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                $this->storage->delete($diskPath);
                $existing = $this->repository()->findBySha256($sha256);
                if ($existing !== null) {
                    $existing['existing'] = true;
                    return $existing;
                }
            }
            $this->storage->delete($diskPath);
            throw new MediaServiceException('admin.media.error_upload_failed', [], $e);
        } catch (Throwable $e) {
            $this->storage->delete($diskPath);
            throw new MediaServiceException('admin.media.error_upload_failed', [], $e);
        }

        $row = $this->repository()->findById($mediaId);
        if ($row === null) {
            throw new RuntimeException('Failed to load media after upload.');
        }
        $row['existing'] = false;

        return $row;
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Media id must be positive.');
        }

        return $this->repository()->findById($id);
    }

    /** @param array<string, mixed> $file */
    private function normalizeFile(array $file): array
    {
        $name = (string) ($file['name'] ?? '');
        $tmp = (string) ($file['tmp_path'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $mime = (string) ($file['mime'] ?? '');

        if ($name === '' || $tmp === '') {
            throw new MediaServiceException('admin.media.error_upload_failed');
        }

        return [
            'name' => $name,
            'tmp_path' => $tmp,
            'size' => $size,
            'mime' => $mime,
        ];
    }

    /** @param array{name: string, tmp_path: string, size: int, mime: string} $file */
    private function validateFile(array $file): array
    {
        $tmpPath = $file['tmp_path'];
        if (!is_file($tmpPath)) {
            throw new MediaServiceException('admin.media.error_upload_failed');
        }

        $size = $file['size'];
        if ($size <= 0) {
            $size = (int) filesize($tmpPath);
        }
        if ($size <= 0) {
            throw new MediaServiceException('admin.media.error_upload_failed');
        }

        $maxBytes = (int) ($this->config['max_bytes'] ?? 0);
        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new MediaServiceException('media.upload_too_large');
        }

        $mime = $this->sniffer->detect($tmpPath);
        if ($mime === null || $mime === '') {
            throw new MediaServiceException('admin.media.error_invalid_type');
        }
        if ($mime === 'image/svg+xml') {
            throw new MediaServiceException('admin.media.error_svg_forbidden');
        }

        $allowed = $this->config['allowed_mime'] ?? [];
        if (is_array($allowed) && $allowed !== [] && !in_array($mime, $allowed, true)) {
            throw new MediaServiceException('admin.media.error_invalid_type');
        }

        $extension = $this->sniffer->extensionForMime($mime);
        if ($extension === null) {
            throw new MediaServiceException('admin.media.error_invalid_type');
        }

        $maxByMime = $this->config['max_bytes_by_mime'] ?? [];
        if (is_array($maxByMime) && isset($maxByMime[$mime]) && is_numeric($maxByMime[$mime])) {
            $mimeMax = (int) $maxByMime[$mime];
            if ($mimeMax > 0 && $size > $mimeMax) {
                throw new MediaServiceException('media.upload_mime_too_large', [
                    'max' => $mimeMax,
                ]);
            }
        }

        return [
            'mime' => $mime,
            'size' => $size,
            'extension' => $extension,
        ];
    }

    private function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            throw new MediaServiceException('admin.media.error_upload_failed');
        }

        return $hash;
    }

    /** @param array{name: string, tmp_path: string, size: int, mime: string} $file */
    private function moveToStorage(array $file, string $diskPath): void
    {
        $upload = [
            'tmp_name' => $file['tmp_path'],
        ];

        try {
            $quarantine = $this->storage->storeUploadedToQuarantine($upload);
            $this->storage->moveQuarantineToDiskPath($quarantine['absolute_path'], $diskPath);
        } catch (Throwable $e) {
            throw new MediaServiceException('admin.media.error_upload_failed', [], $e);
        }
    }

    /** @param array<string, mixed> $data */
    private function persistMedia(array $data): int
    {
        return $this->repository()->create($data);
    }

    /** @return array{uuid: string, disk_path: string} */
    private function generateFilename(string $extension): array
    {
        $uuid = bin2hex(random_bytes(16));
        $diskPath = $this->storage->buildDiskPath(new DateTimeImmutable(), $uuid, $extension);

        return [
            'uuid' => $uuid,
            'disk_path' => $diskPath,
        ];
    }

    private function repository(): MediaRepository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        if (!$this->db->healthCheck()) {
            throw new RuntimeException('Database unavailable.');
        }

        try {
            $this->repository = new MediaRepository($this->db);
        } catch (Throwable $e) {
            throw new RuntimeException('Database unavailable.', 0, $e);
        }

        return $this->repository;
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function deleteTemp(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        $errorInfo = $e->errorInfo ?? [];
        $driverCode = $errorInfo[1] ?? null;
        if (is_int($driverCode) && in_array($driverCode, [19, 1062], true)) {
            return true;
        }

        return false;
    }
}
