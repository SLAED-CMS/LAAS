<?php
declare(strict_types=1);

namespace Laas\Domain\Media;

interface MediaWriteServiceInterface
{
    /**
     * @param array{name: string, tmp_path: string, size: int, mime: string} $file
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @mutation
     */
    public function upload(array $file, array $options = []): array;

    /** @mutation */
    public function delete(int $id): void;

    /** @mutation */
    public function setPublic(int $id, bool $isPublic, ?string $token): void;
}
