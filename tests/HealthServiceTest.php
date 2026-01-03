<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\StorageDriverInterface;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\HealthService;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase
{
    public function testHealthWithoutWriteCheckDoesNotWriteStorage(): void
    {
        $driver = new SpyStorageDriver();
        $storage = new StorageService(dirname(__DIR__), $driver);
        $checker = new ConfigSanityChecker();
        $config = [
            'media' => ['max_bytes' => 1, 'allowed_mime' => ['image/jpeg']],
            'storage' => ['default' => 'local', 'disks' => ['s3' => []]],
        ];

        $service = new HealthService(
            dirname(__DIR__),
            static fn (): bool => true,
            $storage,
            $checker,
            $config,
            false
        );

        $service->check();

        $this->assertSame(0, $driver->writes);
        $this->assertSame(0, $driver->deletes);
        $this->assertSame(1, $driver->existsCalls);
    }

    public function testHealthWithWriteCheckWritesAndDeletes(): void
    {
        $driver = new SpyStorageDriver();
        $storage = new StorageService(dirname(__DIR__), $driver);
        $checker = new ConfigSanityChecker();
        $config = [
            'media' => ['max_bytes' => 1, 'allowed_mime' => ['image/jpeg']],
            'storage' => ['default' => 'local', 'disks' => ['s3' => []]],
        ];

        $service = new HealthService(
            dirname(__DIR__),
            static fn (): bool => true,
            $storage,
            $checker,
            $config,
            true
        );

        $service->check();

        $this->assertSame(1, $driver->writes);
        $this->assertSame(1, $driver->deletes);
        $this->assertSame(1, $driver->existsCalls);
    }
}

final class SpyStorageDriver implements StorageDriverInterface
{
    public int $writes = 0;
    public int $deletes = 0;
    public int $existsCalls = 0;

    public function name(): string
    {
        return 's3';
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        $this->writes++;
        return true;
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        $this->writes++;
        return true;
    }

    public function getStream(string $diskPath)
    {
        return false;
    }

    public function exists(string $diskPath): bool
    {
        $this->existsCalls++;
        return false;
    }

    public function delete(string $diskPath): bool
    {
        $this->deletes++;
        return true;
    }

    public function size(string $diskPath): int
    {
        return 0;
    }

    public function stats(): array
    {
        return ['requests' => 0, 'total_ms' => 0.0];
    }
}
