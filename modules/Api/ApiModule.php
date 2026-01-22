<?php

declare(strict_types=1);

namespace Laas\Modules\Api;

use Laas\Core\Container\Container;
use Laas\Database\DatabaseManager;
use Laas\Modules\ModuleInterface;
use Laas\Routing\RouteHandlerSpec;
use Laas\Routing\RouteHandlerTokens;
use Laas\Routing\Router;
use Laas\View\View;

final class ApiModule implements ModuleInterface
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null,
        private ?Container $container = null
    ) {
    }

    public function registerRoutes(Router $router): void
    {
        $contextKey = self::class;
        $router->registerContext($contextKey, [
            'view' => $this->view,
            'container' => $this->container,
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

            $ctor = (new \ReflectionClass($class))->getConstructor();
            $paramCount = $ctor?->getNumberOfParameters() ?? 0;
            $useContainer = $this->container !== null;

            $ctorTokens = RouteHandlerTokens::fromParamCountApi($paramCount, $useContainer);
            $router->addRoute($method, $path, RouteHandlerSpec::controller(
                $contextKey,
                $class,
                $action,
                $ctorTokens,
                true
            ));
        }
    }
}
