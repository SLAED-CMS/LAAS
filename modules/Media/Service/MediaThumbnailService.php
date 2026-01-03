<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Laas\Support\AuditLogger;

final class MediaThumbnailService
{
    private const REASON_TOO_MANY_PIXELS = 'too_many_pixels';
    private const REASON_DECODE_FAILED = 'decode_failed';
    private const REASON_UNSUPPORTED = 'unsupported';

    private StorageService $storage;
    private ?ImageDecoderInterface $decoder;
    private ?AuditLogger $auditLogger;

    public function __construct(
        StorageService $storage,
        ?ImageDecoderInterface $decoder = null,
        ?AuditLogger $auditLogger = null
    )
    {
        $this->storage = $storage;
        $this->decoder = $decoder ?? $this->detectDecoder();
        $this->auditLogger = $auditLogger;
    }

    public function resolveThumbPath(array $media, string $variant, array $config): ?array
    {
        $variants = $this->variants($config);
        if (!isset($variants[$variant])) {
            return null;
        }

        $mime = (string) ($media['mime_type'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $diskPath = $this->thumbDiskPath($media, $variant, $config);
        if ($diskPath === null) {
            return null;
        }

        return [
            'disk_path' => $diskPath,
            'absolute_path' => $this->storage->absolutePath($diskPath),
            'mime' => $this->thumbMime($config),
        ];
    }

    public function getThumbReason(array $media, string $variant, array $config): ?string
    {
        $path = $this->thumbReasonDiskPath($media, $variant, $config);
        if ($path === null) {
            return null;
        }

        $absolute = $this->storage->absolutePath($path);
        if (!is_file($absolute)) {
            return null;
        }

        $reason = trim((string) file_get_contents($absolute));
        if (!in_array($reason, [
            self::REASON_TOO_MANY_PIXELS,
            self::REASON_DECODE_FAILED,
            self::REASON_UNSUPPORTED,
        ], true)) {
            return null;
        }

        return $reason;
    }

    /** @return array{generated: int, skipped: int, failed: int} */
    public function sync(array $media, array $config): array
    {
        $variants = $this->variants($config);
        $result = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

        if ($variants === []) {
            return $result;
        }

        $mime = (string) ($media['mime_type'] ?? '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $result;
        }

        $sourcePath = $this->storage->absolutePath((string) ($media['disk_path'] ?? ''));
        if (!is_file($sourcePath)) {
            return $result;
        }

        $deadline = $this->deadlineSeconds();
        $maxPixels = $this->maxPixels($config);
        foreach ($variants as $variant => $maxWidth) {
            $diskPath = $this->thumbDiskPath($media, $variant, $config);
            if ($diskPath === null) {
                $result['failed']++;
                continue;
            }

            $reasonDiskPath = $this->thumbReasonDiskPath($media, $variant, $config);
            $absolutePath = $this->storage->absolutePath($diskPath);
            if (is_file($absolutePath)) {
                $this->clearReason($reasonDiskPath);
                $result['skipped']++;
                continue;
            }

            if ($this->decoder === null || !$this->decoder->supportsMime($mime)) {
                $this->writeReason($reasonDiskPath, self::REASON_UNSUPPORTED);
                $result['failed']++;
                continue;
            }

            if (microtime(true) > $deadline) {
                $this->writeReason($reasonDiskPath, self::REASON_DECODE_FAILED);
                $result['failed']++;
                continue;
            }

            $width = $this->decoder->getWidth($sourcePath);
            $height = $this->decoder->getHeight($sourcePath);
            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                $this->writeReason($reasonDiskPath, self::REASON_DECODE_FAILED);
                $result['failed']++;
                continue;
            }

            $pixels = $width * $height;
            if ($maxPixels > 0 && $pixels > $maxPixels) {
                $this->writeReason($reasonDiskPath, self::REASON_TOO_MANY_PIXELS);
                $this->auditPixelsRejected($media, $width, $height, $pixels);
                $result['failed']++;
                continue;
            }

            $ok = $this->decoder->createThumbnail(
                $sourcePath,
                $absolutePath,
                $maxWidth,
                $this->thumbFormat($config),
                $this->thumbQuality($config),
                $maxPixels,
                $deadline
            );

            if (!$ok) {
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
                $this->writeReason($reasonDiskPath, self::REASON_DECODE_FAILED);
                $result['failed']++;
                continue;
            }

            if (!$this->decoder->stripMetadata($absolutePath)) {
                if (is_file($absolutePath)) {
                    @unlink($absolutePath);
                }
                $this->writeReason($reasonDiskPath, self::REASON_DECODE_FAILED);
                $result['failed']++;
                continue;
            }

            $this->clearReason($reasonDiskPath);
            $result['generated']++;
        }

        return $result;
    }

    private function thumbDiskPath(array $media, string $variant, array $config): ?string
    {
        $diskPath = (string) ($media['disk_path'] ?? '');
        $sha256 = (string) ($media['sha256'] ?? '');
        if ($diskPath === '' || $sha256 === '') {
            return null;
        }

        $matches = [];
        if (!preg_match('#^uploads/(\\d{4})/(\\d{2})/#', $diskPath, $matches)) {
            return null;
        }

        $year = $matches[1];
        $month = $matches[2];
        $algo = $this->thumbAlgoVersion($config);
        $ext = $this->thumbExtension($config);

        return sprintf('uploads/_cache/%s/%s/%s/%s_v%d.%s', $year, $month, $sha256, $variant, $algo, $ext);
    }

    private function thumbReasonDiskPath(array $media, string $variant, array $config): ?string
    {
        $diskPath = $this->thumbDiskPath($media, $variant, $config);
        if ($diskPath === null) {
            return null;
        }

        return $diskPath . '.reason';
    }

    /** @return array<string, int> */
    private function variants(array $config): array
    {
        $variants = $config['thumb_variants'] ?? [];
        if (!is_array($variants)) {
            return [];
        }

        $result = [];
        foreach ($variants as $name => $width) {
            if (!is_string($name) || $name === '' || !is_numeric($width)) {
                continue;
            }
            $width = (int) $width;
            if ($width <= 0) {
                continue;
            }
            $result[$name] = $width;
        }

        return $result;
    }

    private function thumbFormat(array $config): string
    {
        $format = strtolower((string) ($config['thumb_format'] ?? 'webp'));
        return in_array($format, ['webp', 'jpg', 'jpeg', 'png'], true) ? $format : 'webp';
    }

    private function thumbExtension(array $config): string
    {
        $format = $this->thumbFormat($config);
        return $format === 'jpeg' ? 'jpg' : $format;
    }

    private function thumbMime(array $config): string
    {
        return match ($this->thumbFormat($config)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/webp',
        };
    }

    private function thumbQuality(array $config): int
    {
        $quality = (int) ($config['thumb_quality'] ?? 82);
        return max(1, min(100, $quality));
    }

    private function thumbAlgoVersion(array $config): int
    {
        $algo = (int) ($config['thumb_algo_version'] ?? 1);
        return max(1, $algo);
    }

    private function maxPixels(array $config): int
    {
        $max = (int) ($config['image_max_pixels'] ?? 40000000);
        return $max > 0 ? $max : 40000000;
    }

    private function detectDecoder(): ?ImageDecoderInterface
    {
        $gd = new GdImageDecoder();
        if ($gd->supportsMime('image/jpeg')) {
            return $gd;
        }

        $imagick = new ImagickImageDecoder();
        if ($imagick->supportsMime('image/jpeg')) {
            return $imagick;
        }

        return null;
    }

    private function deadlineSeconds(): float
    {
        $maxExecution = (int) ini_get('max_execution_time');
        $limit = $maxExecution > 0 ? min(5, $maxExecution) : 5;
        return microtime(true) + $limit;
    }

    private function writeReason(?string $diskPath, string $reason): void
    {
        if ($diskPath === null || $reason === '') {
            return;
        }

        $absolute = $this->storage->absolutePath($diskPath);
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        @file_put_contents($absolute, $reason, LOCK_EX);
    }

    private function clearReason(?string $diskPath): void
    {
        if ($diskPath === null) {
            return;
        }

        $absolute = $this->storage->absolutePath($diskPath);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function auditPixelsRejected(array $media, int $width, int $height, int $pixels): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $id = (int) ($media['id'] ?? 0);
        $this->auditLogger->log(
            'media.thumb.rejected_pixels',
            'media_thumb',
            $id > 0 ? $id : null,
            [
                'width' => $width,
                'height' => $height,
                'pixels' => $pixels,
            ],
            null,
            ''
        );
    }
}
