<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\StorageDriverInterface;
use Laas\Modules\Media\Service\StorageService;
use Laas\Support\ConfigSanityChecker;
use Laas\Support\OpsChecker;
use PHPUnit\Framework\TestCase;

final class OpsCheckCommandTest extends TestCase
{
    public function testOkReturnsZero(): void
    {
        $root = $this->createRoot();
        $storage = new StorageService($root, new SpyOpsStorageDriver());
        $checker = new ConfigSanityChecker();

        $ops = new OpsChecker(
            $root,
            static fn (): bool => true,
            $storage,
            $checker,
            [
                'media' => ['max_bytes' => 1, 'allowed_mime' => ['image/jpeg']],
                'storage' => ['default' => 'local', 'default_raw' => 'local', 'disks' => ['s3' => []]],
                'db' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]
        );

        $result = $ops->run();
        $this->assertSame(0, $result['code']);
    }

    public function testMissingCriticalConfigReturnsOne(): void
    {
        $root = $this->createRoot();
        $storage = new StorageService($root, new SpyOpsStorageDriver());
        $checker = new ConfigSanityChecker();

        $ops = new OpsChecker(
            $root,
            static fn (): bool => true,
            $storage,
            $checker,
            [
                'media' => [],
                'storage' => ['default' => 'local', 'default_raw' => 'local', 'disks' => ['s3' => []]],
                'db' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]
        );

        $result = $ops->run();
        $this->assertSame(1, $result['code']);
    }

    private function createRoot(): string
    {
        $root = sys_get_temp_dir() . '/laas_ops_' . bin2hex(random_bytes(4));
        @mkdir($root . '/storage/logs', 0775, true);
        @mkdir($root . '/storage/sessions', 0775, true);
        @mkdir($root . '/storage/cache', 0775, true);
        @mkdir($root . '/storage/backups', 0775, true);
        return $root;
    }
}

final class SpyOpsStorageDriver implements StorageDriverInterface
{
    public function name(): string
    {
        return 'local';
    }

    public function put(string $diskPath, string $sourcePath): bool
    {
        return false;
    }

    public function putContents(string $diskPath, string $contents): bool
    {
        return false;
    }

    public function getStream(string $diskPath)
    {
        return false;
    }

    public function exists(string $diskPath): bool
    {
        return true;
    }

    public function delete(string $diskPath): bool
    {
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
