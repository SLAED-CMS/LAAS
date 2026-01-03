<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class GdImageDecoder implements ImageDecoderInterface
{
    public function supportsMime(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png' => function_exists('imagecreatefrompng'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default => false,
        };
    }

    public function getWidth(string $sourcePath): ?int
    {
        $info = @getimagesize($sourcePath);
        if (!is_array($info) || empty($info[0])) {
            return null;
        }

        return (int) $info[0];
    }

    public function getHeight(string $sourcePath): ?int
    {
        $info = @getimagesize($sourcePath);
        if (!is_array($info) || empty($info[1])) {
            return null;
        }

        return (int) $info[1];
    }

    public function stripMetadata(string $path): bool
    {
        return is_file($path);
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
        if (!is_file($sourcePath) || $maxWidth <= 0) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        if (!is_array($info) || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
            return false;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = (string) $info['mime'];
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        if ($width * $height > $maxPixels) {
            return false;
        }

        if (!$this->supportsMime($mime)) {
            return false;
        }

        if (!$this->hasMemory($width, $height)) {
            return false;
        }

        if (microtime(true) > $deadline) {
            return false;
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => false,
        };
        if (!is_resource($src) && !($src instanceof \GdImage)) {
            return false;
        }

        $ratio = $width > $maxWidth ? ($maxWidth / $width) : 1.0;
        $targetWidth = (int) max(1, round($width * $ratio));
        $targetHeight = (int) max(1, round($height * $ratio));

        $dst = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($dst === false) {
            $this->destroy($src);
            return false;
        }

        $format = strtolower($format);
        if (in_array($format, ['jpg', 'jpeg'], true)) {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $targetWidth, $targetHeight, $white);
        } elseif (in_array($format, ['png', 'webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            $this->destroy($src);
            $this->destroy($dst);
            return false;
        }

        if (microtime(true) > $deadline) {
            $this->destroy($src);
            $this->destroy($dst);
            return false;
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->destroy($src);
            $this->destroy($dst);
            return false;
        }

        $saved = match ($format) {
            'webp' => function_exists('imagewebp') ? imagewebp($dst, $targetPath, $quality) : false,
            'jpg', 'jpeg' => imagejpeg($dst, $targetPath, $quality),
            'png' => imagepng($dst, $targetPath, $this->pngQuality($quality)),
            default => false,
        };

        $this->destroy($src);
        $this->destroy($dst);

        return $saved === true;
    }

    private function pngQuality(int $quality): int
    {
        $quality = max(0, min(100, $quality));
        return (int) max(0, min(9, round((100 - $quality) / 10)));
    }

    private function hasMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimit();
        if ($limit <= 0) {
            return true;
        }

        $needed = $width * $height * 5;
        $available = $limit - memory_get_usage(true);
        return $available > $needed;
    }

    private function memoryLimit(): int
    {
        $raw = ini_get('memory_limit');
        if ($raw === false || $raw === '' || $raw === '-1') {
            return 0;
        }

        $raw = trim((string) $raw);
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function destroy(object $image): void
    {
        if ($image instanceof \GdImage) {
            imagedestroy($image);
        }
    }
}
