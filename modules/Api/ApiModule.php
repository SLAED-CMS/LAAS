<?php
declare(strict_types=1);

namespace Laas\Modules\Api;

use Laas\Modules\Api\Controller\PingController;
use Laas\Modules\ModuleInterface;
use Laas\Routing\Router;
use Laas\View\View;

final class ApiModule implements ModuleInterface
{
    public function __construct(private View $view)
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->addRoute('GET', '/api/v1/ping', function ($request, array $vars = []) {
            $controller = new PingController();
            return $controller->ping($request);
        });
    }
}
