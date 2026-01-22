<?php

declare(strict_types=1);

namespace Laas\Modules;

use Laas\Core\Container\Container;
use Laas\Events\EventDispatcherInterface;
use Laas\Routing\Router;

interface ModuleLifecycleInterface
{
    public function registerBindings(Container $container): void;

    public function registerRoutes(Router $router): void;

    public function registerListeners(EventDispatcherInterface $events): void;
}
