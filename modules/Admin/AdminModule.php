<?php
declare(strict_types=1);

namespace Laas\Modules\Admin;

use Laas\Modules\ModuleInterface;
use Laas\Routing\Router;
use Laas\View\View;
use Laas\Database\DatabaseManager;

final class AdminModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    )
    {
    }

    public function registerRoutes(Router $router): void
    {
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
                $controller = new $class($this->view, $this->db);
                return $controller->{$action}($request);
            });
        }
    }
}
