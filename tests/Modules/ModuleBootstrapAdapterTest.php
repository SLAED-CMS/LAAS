<?php

declare(strict_types=1);

use Laas\Core\Container\Container;
use Laas\Events\SimpleEventDispatcher;
use Laas\Modules\ModuleBootstrapAdapter;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ModuleBootstrapAdapterTest extends TestCase
{
    public function testAdapterInvokesLifecycleHooks(): void
    {
        $module = new class implements ModuleLifecycleInterface {
            public bool $bindingsCalled = false;
            public bool $routesCalled = false;
            public bool $listenersCalled = false;

            public function registerBindings(Container $container): void
            {
                $this->bindingsCalled = true;
            }

            public function registerRoutes(Router $router): void
            {
                $this->routesCalled = true;
            }

            public function registerListeners(\Laas\Events\EventDispatcherInterface $events): void
            {
                $this->listenersCalled = true;
            }
        };

        $container = new Container();
        $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-router-test', true);
        $dispatcher = new SimpleEventDispatcher();

        ModuleBootstrapAdapter::callRegisterBindingsIfSupported($module, $container);
        ModuleBootstrapAdapter::callRegisterRoutesIfSupported($module, $router);
        ModuleBootstrapAdapter::callRegisterListenersIfSupported($module, $dispatcher);

        $this->assertTrue($module->bindingsCalled);
        $this->assertTrue($module->routesCalled);
        $this->assertTrue($module->listenersCalled);
    }

    public function testAdapterIsNoOpForNonLifecycleModules(): void
    {
        $module = new class {
            public bool $called = false;
        };

        $container = new Container();
        $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-router-test', true);
        $dispatcher = new SimpleEventDispatcher();

        ModuleBootstrapAdapter::callRegisterBindingsIfSupported($module, $container);
        ModuleBootstrapAdapter::callRegisterRoutesIfSupported($module, $router);
        ModuleBootstrapAdapter::callRegisterListenersIfSupported($module, $dispatcher);

        $this->assertFalse($module->called);
    }
}
