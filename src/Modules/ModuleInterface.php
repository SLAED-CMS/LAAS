<?php
declare(strict_types=1);

namespace Laas\Modules;

use Laas\Routing\Router;

interface ModuleInterface
{
    public function registerRoutes(Router $router): void;
}
