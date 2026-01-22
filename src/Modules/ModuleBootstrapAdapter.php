<?php

declare(strict_types=1);

namespace Laas\Modules;

use Laas\Core\Container\Container;
use Laas\Events\EventDispatcherInterface;
use Laas\Routing\Router;

final class ModuleBootstrapAdapter
{
    public static function callRegisterBindingsIfSupported(object $module, Container $container): void
    {
        if (!$module instanceof ModuleLifecycleInterface) {
            return;
        }

        $module->registerBindings($container);
    }

    public static function callRegisterRoutesIfSupported(object $module, Router $router): void
    {
        if (!$module instanceof ModuleLifecycleInterface) {
            return;
        }

        $module->registerRoutes($router);
    }

    public static function callRegisterListenersIfSupported(object $module, EventDispatcherInterface $events): void
    {
        if (!$module instanceof ModuleLifecycleInterface) {
            return;
        }

        $module->registerListeners($events);
    }
}
