<?php
declare(strict_types=1);

use Laas\Core\Kernel;
use Laas\Domain\Pages\PagesReadServiceInterface;
use Laas\Domain\Pages\PagesService;
use Laas\Domain\Pages\PagesWriteServiceInterface;
use Laas\Domain\Support\ReadOnlyProxy;
use PHPUnit\Framework\TestCase;

final class ReadOnlyBindingsTest extends TestCase
{
    public function testReadBindingsUseProxy(): void
    {
        $root = dirname(__DIR__);
        $kernel = new Kernel($root);
        $container = $kernel->container();

        $readService = $container->get(PagesReadServiceInterface::class);
        $writeService = $container->get(PagesWriteServiceInterface::class);

        $this->assertInstanceOf(PagesReadServiceInterface::class, $readService);
        $this->assertInstanceOf(ReadOnlyProxy::class, $readService);

        $this->assertInstanceOf(PagesWriteServiceInterface::class, $writeService);
        $this->assertInstanceOf(PagesService::class, $writeService);
        $this->assertNotInstanceOf(ReadOnlyProxy::class, $writeService);
    }
}
