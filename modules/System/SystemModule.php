<?php
declare(strict_types=1);

namespace Laas\Modules\System;

use Laas\Modules\ModuleInterface;
use Laas\Modules\System\Controller\HomeController;
use Laas\Database\DatabaseManager;
use Laas\Core\Container\Container;
use Laas\Routing\Router;
use Laas\View\View;

final class SystemModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
    )
    {
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
            $paramCount = $ctor?->getNumberOfParameters() ?? 0;
            $useContainer = $this->container !== null;

            $router->addRoute($method, $path, function ($request, array $vars = []) use ($class, $action, $paramCount, $useContainer) {
                if ($useContainer && $paramCount >= 4) {
                    $controller = new $class($this->view, null, null, $this->container);
                } elseif ($useContainer && $paramCount >= 3) {
                    $controller = new $class($this->view, null, $this->container);
                } elseif ($useContainer && $paramCount >= 2) {
                    $controller = new $class($this->view, $this->container);
                } elseif ($paramCount >= 2) {
                    $controller = new $class($this->view, null);
                } elseif ($paramCount >= 1) {
                    $controller = new $class($this->view);
                } else {
                    $controller = new $class();
                }
                return $controller->{$action}($request);
            });
        }
    }
}
