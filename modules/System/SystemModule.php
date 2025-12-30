<?php
declare(strict_types=1);

namespace Laas\Modules\System;

use Laas\Modules\ModuleInterface;
use Laas\Modules\System\Controller\HomeController;
use Laas\Routing\Router;
use Laas\View\View;

final class SystemModule implements ModuleInterface
{
    public function __construct(private View $view)
    {
    }

    public function registerRoutes(Router $router): void
    {
        $view = $this->view;

        $router->addRoute('GET', '/', function ($request, array $vars = []) use ($view) {
            $controller = new HomeController($view);
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

            $router->addRoute($method, $path, function ($request, array $vars = []) use ($class, $action) {
                $controller = new $class();
                return $controller->{$action}($request);
            });
        }
    }
}
