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

        $appConfig = $ctx->appConfig['app'] ?? [];
        $warmEnabled = (bool) ($appConfig['routing_cache_warm'] ?? false);
        if (!$warmEnabled) {
            return;
        }

        $force = (bool) ($appConfig['routing_cache_warm_force'] ?? false);
        $result = $router->warmCache($force);

        if ($ctx->debug) {
            $status = (string) $result['status'];
            $fingerprint = (string) $result['fingerprint'];
            error_log('[bootstrap.routing] warmed route cache (' . $status . ') fingerprint=' . $fingerprint);
        }
    }
}
