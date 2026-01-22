<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Core\Container\Container;
use PHPUnit\Framework\TestCase;

final class ModulesBootstrapTest extends TestCase
{
    public function testModulesBootstrapSetsFlag(): void
    {
        $container = new Container();
        $ctx = new BootContext(__DIR__, $container, [], false);
        $runner = new BootstrapsRunner([new ModulesBootstrap()]);

        $runner->run($ctx);

        $this->assertTrue($container->get('boot.modules'));
        $this->assertSame(1, $runner->count());
    }
}
