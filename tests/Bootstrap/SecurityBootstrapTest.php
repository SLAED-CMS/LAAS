<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Core\Container\Container;
use Laas\Core\Container\NotFoundException;
use PHPUnit\Framework\TestCase;

final class SecurityBootstrapTest extends TestCase
{
    public function testBootstrapDisabledDoesNotSetFlag(): void
    {
        $container = new Container();
        $ctx = new BootContext(__DIR__, $container, [], false);
        $runner = new BootstrapsRunner([]);

        $runner->run($ctx);

        $this->assertSame(0, $runner->count());
        $this->expectException(NotFoundException::class);
        $container->get('boot.security');
    }

    public function testBootstrapEnabledSetsFlag(): void
    {
        $container = new Container();
        $ctx = new BootContext(__DIR__, $container, [], false);
        $runner = new BootstrapsRunner([new SecurityBootstrap()]);

        $runner->run($ctx);

        $this->assertTrue($container->get('boot.security'));
        $this->assertSame(1, $runner->count());
    }
}
