<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\LocalStorageDriver;
use PHPUnit\Framework\TestCase;

final class StorageContractTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/laas_storage_' . bin2hex(random_bytes(6));
        $storageDir = $this->rootPath . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    public function testStorageContractPutGetDeleteExists(): void
    {
        $driver = new LocalStorageDriver($this->rootPath);
        $diskPath = 'uploads/2026/01/' . bin2hex(random_bytes(4)) . '.txt';
        $sourcePath = $this->rootPath . '/source.txt';
        file_put_contents($sourcePath, 'data');

        $this->assertTrue($driver->put($diskPath, $sourcePath));
        $this->assertTrue($driver->exists($diskPath));
        $this->assertSame(4, $driver->size($diskPath));

        $stream = $driver->getStream($diskPath);
        $this->assertIsResource($stream);
        $this->assertSame('data', stream_get_contents($stream));
        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->assertTrue($driver->delete($diskPath));
        $this->assertFalse($driver->exists($diskPath));
    }

    public function testStorageContractHandlesMissingFiles(): void
    {
        $driver = new LocalStorageDriver($this->rootPath);
        $diskPath = 'uploads/missing.txt';

        $this->assertFalse($driver->exists($diskPath));
        $this->assertFalse($driver->getStream($diskPath));
        $this->assertTrue($driver->delete($diskPath));
        $this->assertSame(0, $driver->size($diskPath));
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }
            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
