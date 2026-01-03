<?php
declare(strict_types=1);

namespace Laas\Modules\Media\Service;

interface StorageDriverInterface
{
    public function name(): string;

    public function put(string $diskPath, string $sourcePath): bool;

    public function putContents(string $diskPath, string $contents): bool;

    /** @return resource|false */
    public function getStream(string $diskPath);

    public function exists(string $diskPath): bool;

    public function delete(string $diskPath): bool;

    public function size(string $diskPath): int;

    /** @return array{requests: int, total_ms: float} */
    public function stats(): array;
}
