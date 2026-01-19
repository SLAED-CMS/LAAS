<?php
declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Core\Container\NotFoundException;
use Laas\Core\Kernel;
use Laas\Database\DatabaseManager;
use Laas\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testBindCreatesNewInstanceEachTime(): void
    {
        $container = new Container();
        $container->bind('dummy', DummyService::class);

        $first = $container->get('dummy');
        $second = $container->get('dummy');

        $this->assertInstanceOf(DummyService::class, $first);
        $this->assertInstanceOf(DummyService::class, $second);
        $this->assertNotSame($first, $second);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton('dummy', DummyService::class);

        $first = $container->get('dummy');
        $second = $container->get('dummy');

        $this->assertSame($first, $second);
    }

    public function testUnknownBindingThrowsNotFound(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('missing');
    }

    public function testKernelProvidesContainerAndCoreBindings(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);

        $container = $kernel->container();
        $this->assertInstanceOf(Container::class, $container);

        $config = $container->get('config');
        $this->assertIsArray($config);

        $translator = $container->get('translator');
        $this->assertInstanceOf(Translator::class, $translator);

        $db = $container->get('db');
        $this->assertInstanceOf(DatabaseManager::class, $db);
    }
}

final class DummyService
{
}
