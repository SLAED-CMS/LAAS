<?php
declare(strict_types=1);

use Laas\Modules\Media\Service\MimeSniffer;
use Laas\Modules\Media\Service\StorageService;
use PHPUnit\Framework\TestCase;

final class MediaServiceTest extends TestCase
{
    public function testBuildDiskPath(): void
    {
        $service = new StorageService(__DIR__);
        $now = new DateTimeImmutable('2026-01-02 12:00:00');

        $path = $service->buildDiskPath($now, 'uuid-test', 'jpg');

        $this->assertSame('uploads/2026/01/uuid-test.jpg', $path);
    }

    public function testExtensionForMime(): void
    {
        $sniffer = new MimeSniffer();

        $this->assertSame('jpg', $sniffer->extensionForMime('image/jpeg'));
        $this->assertSame('png', $sniffer->extensionForMime('image/png'));
        $this->assertNull($sniffer->extensionForMime('image/gif'));
        $this->assertNull($sniffer->extensionForMime('application/zip'));
    }
}
