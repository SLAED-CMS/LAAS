<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

interface ImageDecoderInterface
{
    public function supportsMime(string $mime): bool;

    public function getWidth(string $sourcePath): ?int;

    public function getHeight(string $sourcePath): ?int;

    public function stripMetadata(string $path): bool;

    public function createThumbnail(
        string $sourcePath,
        string $targetPath,
        int $maxWidth,
        string $format,
        int $quality,
        int $maxPixels,
        float $deadline
    ): bool;
}
