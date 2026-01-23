<?php

declare(strict_types=1);

namespace Laas\Modules\DemoBlog;

use Laas\Core\Container\Container;
use Laas\Events\EventDispatcherInterface;
use Laas\Modules\ModuleInterface;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Routing\RouteHandlerSpec;
use Laas\Routing\RouteHandlerTokens;
use Laas\Routing\Router;
use Laas\View\View;

final class DemoBlogModule implements ModuleInterface, ModuleLifecycleInterface
{
    public function __construct(
        private View $view
    ) {
    }

    public function registerBindings(Container $container): void
    {
    }

    public function registerRoutes(Router $router): void
    {
        $contextKey = self::class;
        $router->registerContext($contextKey, [
            'view' => $this->view,
        ]);

        $routes = require __DIR__ . '/routes.php';
        foreach ($routes as $route) {
            [$method, $path, $handler] = $route;
            if (!is_array($handler) || count($handler) !== 2) {
                continue;
            }

            [$class, $action] = $handler;
            if (!is_string($class) || !is_string($action)) {
                continue;
            }

            $router->addRoute($method, $path, RouteHandlerSpec::controller(
                $contextKey,
                $class,
                $action,
                RouteHandlerTokens::viewOnly(),
                true
            ));
        }
    }

    public function registerListeners(EventDispatcherInterface $events): void
    {
    }
}
