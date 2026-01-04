<?php
declare(strict_types=1);

namespace Tests\Security\Support;

use Laas\Modules\Media\Service\StorageDriverInterface;

final class StorageSpy implements StorageDriverInterface
{
    public array $puts = [];
    public array $putContents = [];
    public array $exists = [];
    public array $deleted = [];
    public array $streams = [];
    public int $sizeResult = 0;
    public bool $existsResult = true;

    public function name(): string
    {
        return 'local';
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        $this->puts[] = [$diskPath, $sourcePath];
        return true;
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        $this->putContents[] = [$diskPath, $contents];
        return true;
    }

    public function getStream(string $diskPath)
    {
        $this->streams[] = $diskPath;
        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            return false;
        }
        fwrite($stream, '');
        rewind($stream);
        return $stream;
    }

    public function exists(string $diskPath): bool
    {
        $this->exists[] = $diskPath;
        return $this->existsResult;
    }

    public function delete(string $diskPath): bool
    {
        $this->deleted[] = $diskPath;
        return true;
    }

    public function size(string $diskPath): int
    {
        return $this->sizeResult;
    }

    public function stats(): array
    {
        return ['requests' => 0, 'total_ms' => 0.0];
    }
}
