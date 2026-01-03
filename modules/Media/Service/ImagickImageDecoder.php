<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

use Imagick;
use ImagickException;

final class ImagickImageDecoder implements ImageDecoderInterface
{
    public function supportsMime(string $mime): bool
    {
        return class_exists(Imagick::class)
            && in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    public function getWidth(string $sourcePath): ?int
    {
        if (!class_exists(Imagick::class) || !is_file($sourcePath)) {
            return null;
        }

        try {
            $img = new Imagick();
            $img->pingImage($sourcePath);
            $width = $img->getImageWidth();
            $img->clear();
            return $width > 0 ? $width : null;
        } catch (ImagickException) {
            return null;
        }
    }

    public function getHeight(string $sourcePath): ?int
    {
        if (!class_exists(Imagick::class) || !is_file($sourcePath)) {
            return null;
        }

        try {
            $img = new Imagick();
            $img->pingImage($sourcePath);
            $height = $img->getImageHeight();
            $img->clear();
            return $height > 0 ? $height : null;
        } catch (ImagickException) {
            return null;
        }
    }

    public function stripMetadata(string $path): bool
    {
        if (!class_exists(Imagick::class) || !is_file($path)) {
            return false;
        }

        try {
            $img = new Imagick();
            $img->readImage($path);
            $img->stripImage();
            $img->writeImage($path);
            $img->clear();
            return true;
        } catch (ImagickException) {
            return false;
        }
    }

    public function createThumbnail(
        string $sourcePath,
        string $targetPath,
        int $maxWidth,
        string $format,
        int $quality,
        int $maxPixels,
        float $deadline
    ): bool {
        if (!class_exists(Imagick::class) || !is_file($sourcePath) || $maxWidth <= 0) {
            return false;
        }

        try {
            if (microtime(true) > $deadline) {
                return false;
            }

            $img = new Imagick();
            $img->pingImage($sourcePath);

            $width = $img->getImageWidth();
            $height = $img->getImageHeight();
            if ($width <= 0 || $height <= 0) {
                $img->clear();
                return false;
            }

            if ($width * $height > $maxPixels) {
                $img->clear();
                return false;
            }

            $img->readImage($sourcePath);
            $img->stripImage();
            $img->profileImage('*', null);

            if ($width > $maxWidth) {
                $img->thumbnailImage($maxWidth, 0);
            }

            if (microtime(true) > $deadline) {
                $img->clear();
                return false;
            }

            $format = strtolower($format);
            if (!in_array($format, ['webp', 'jpeg', 'jpg', 'png'], true)) {
                $img->clear();
                return false;
            }

            $dir = dirname($targetPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                $img->clear();
                return false;
            }

            if (in_array($format, ['jpg', 'jpeg'], true)) {
                $img->setImageBackgroundColor('white');
                $img = $img->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $img->setImageFormat($format);
            $img->setImageCompressionQuality(max(1, min(100, $quality)));
            $img->writeImage($targetPath);
            $img->clear();
            return true;
        } catch (ImagickException) {
            return false;
        }
    }
}
