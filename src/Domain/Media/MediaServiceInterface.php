<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

interface MediaServiceInterface
{
    /**
     * @param array{name: string, tmp_path: string, size: int, mime: string} $file
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function upload(array $file, array $options = []): array;
}
