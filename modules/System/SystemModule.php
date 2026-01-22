<?php

declare(strict_types=1);

namespace Laas\Modules\System;

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Modules\ModuleInterface;
use Laas\Modules\System\Controller\HomeController;
use Laas\Routing\Router;
use Laas\View\View;

final class SystemModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
    ) {
    }

    public function registerRoutes(Router $router): void
    {
        $view = $this->view;
        $container = $this->container;

        $router->addRoute('GET', '/', function ($request, array $vars = []) use ($view, $container) {
            $controller = $container !== null
                ? new HomeController($view, $container)
                : new HomeController($view);
            return $controller->index($request);
        });

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

            $router->addRoute($method, $path, function ($request, array $vars = []) use ($class, $action, $params, $paramCount, $useContainer) {
                if ($paramCount <= 0) {
                    $controller = new $class();
                    return $controller->{$action}($request);
                }

                $args = array_fill(0, $paramCount, null);
                $args[0] = $this->view;
                if ($useContainer && $params !== []) {
                    foreach ($params as $index => $param) {
                        $type = $param->getType();
                        if ($type instanceof \ReflectionNamedType && $type->getName() === Container::class) {
                            $args[$index] = $this->container;
                        }
                    }
                }

                $controller = new $class(...$args);
                return $controller->{$action}($request);
            });
        }
    }
}
