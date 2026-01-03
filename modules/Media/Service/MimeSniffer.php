<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

final class MimeSniffer
{
    /** @var array<string, string> */
    private array $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function detect(string $path): ?string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info === false) {
            return null;
        }

        $mime = finfo_file($info, $path);
        finfo_close($info);

        return is_string($mime) ? $mime : null;
    }

    public function extensionForMime(string $mime): ?string
    {
        return $this->mimeToExt[$mime] ?? null;
    }
}
