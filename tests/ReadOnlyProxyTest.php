<?php
declare(strict_types=1);

use Laas\Domain\Support\ReadOnlyProxy;
use PHPUnit\Framework\TestCase;

final class ReadOnlyProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReadOnlyProxy::setLogger(static function (string $message): void {
        });
    }

    protected function tearDown(): void
    {
        ReadOnlyProxy::setLogger(null);
        parent::tearDown();
    }

    public function testReadMethodPassesThrough(): void
    {
        $service = new ReadOnlyProxyReadService();
        $proxy = ReadOnlyProxy::wrap($service, ReadOnlyProxyTestInterface::class);

        $this->assertSame('ok', $proxy->read());
    }

    public function testMutationMethodThrows(): void
    {
        $service = new ReadOnlyProxyWriteService();
        $proxy = ReadOnlyProxy::wrap($service, ReadOnlyProxyTestInterface::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            'Read-only service: mutation method ' . ReadOnlyProxyTestInterface::class . '::write is not allowed'
        );
        $proxy->write();
    }
}

interface ReadOnlyProxyTestInterface
{
    public function read(): string;

    public function write(): void;
}

final class ReadOnlyProxyReadService implements ReadOnlyProxyTestInterface
{
    public function read(): string
    {
        return 'ok';
    }

    public function write(): void
    {
    }
}

final class ReadOnlyProxyWriteService implements ReadOnlyProxyTestInterface
{
    public function read(): string
    {
        return 'ok';
    }

    /** @mutation */
    public function write(): void
    {
    }
}
