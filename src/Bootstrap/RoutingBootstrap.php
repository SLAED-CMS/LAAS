<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

use Laas\Routing\Router;

final class RoutingBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        try {
            $router = $ctx->container->get(Router::class);
        } catch (\Throwable) {
            return;
        }

        if (!$router instanceof Router) {
            return;
        }

        $warmEnabled = (bool) ($ctx->appConfig['routing_cache_warm'] ?? false);
        if (!$warmEnabled) {
            return;
        }

        $force = (bool) ($ctx->appConfig['routing_cache_warm_force'] ?? false);
        $router->warmCache($force);
    }
}
