<?php
declare(strict_types=1);

use Laas\Domain\Support\ReadOnlyProxy;
use PHPUnit\Framework\TestCase;

final class ReadOnlyProxyTest extends TestCase
{
    public function testReadMethodPassesThrough(): void
    {
        $service = new ReadOnlyProxyTestService();
        $proxy = new ReadOnlyProxyTestProxy(
            $service,
            ReadOnlyProxy::allowedMethods($service)
        );

        $this->assertSame('ok', $proxy->read());
    }

    public function testMutationMethodThrows(): void
    {
        $service = new ReadOnlyProxyTestService();
        $proxy = new ReadOnlyProxyTestProxy(
            $service,
            ReadOnlyProxy::allowedMethods($service)
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Read-only service: mutation method write is not allowed');
        $proxy->write();
    }
}

final class ReadOnlyProxyTestService
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

final class ReadOnlyProxyTestProxy extends ReadOnlyProxy
{
    public function read(): string
    {
        return $this->call(__FUNCTION__, func_get_args());
    }

    public function write(): void
    {
        $this->call(__FUNCTION__, func_get_args());
    }
}
