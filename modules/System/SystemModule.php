<?php

declare(strict_types=1);

namespace Laas\Modules\System;

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Events\EventDispatcherInterface;
use Laas\Modules\ModuleInterface;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\System\Controller\HomeController;
use Laas\Routing\RouteHandlerSpec;
use Laas\Routing\RouteHandlerTokens;
use Laas\Routing\Router;
use Laas\View\View;

final class SystemModule implements ModuleInterface, ModuleLifecycleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
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
            'container' => $this->container,
        ]);

        $homeCtor = (new \ReflectionClass(HomeController::class))->getConstructor();
        $homeParamCount = $homeCtor?->getNumberOfParameters() ?? 0;
        $homeTokens = RouteHandlerTokens::fromParamCountTail($homeParamCount, $this->container !== null);
        $router->addRoute('GET', '/', RouteHandlerSpec::controller(
            $contextKey,
            HomeController::class,
            'index',
            $homeTokens,
            false
        ));

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

            $ctor = (new \ReflectionClass($class))->getConstructor();
            $params = $ctor?->getParameters() ?? [];
            $paramCount = $ctor?->getNumberOfParameters() ?? 0;
            $useContainer = $this->container !== null;

            $ctorTokens = RouteHandlerTokens::fromParams($params, $paramCount, $useContainer);
            $router->addRoute($method, $path, RouteHandlerSpec::controller(
                $contextKey,
                $class,
                $action,
                $ctorTokens,
                false
            ));
        }
    }

    public function registerListeners(EventDispatcherInterface $events): void
    {
    }
}
