<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

use Laas\Theme\ThemeRegistry;

final class ViewBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        $ctx->container->singleton('boot.view', static fn (): bool => true);

        $dir = rtrim($ctx->rootPath, '/\\') . '/storage/cache/templates';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $registry = null;
        try {
            $registry = $ctx->container->get(ThemeRegistry::class);
        } catch (\Throwable) {
            return;
        }

        if (!$registry instanceof ThemeRegistry) {
            return;
        }

        try {
            $registry->default();
        } catch (\Throwable $e) {
            if ($ctx->debug) {
                throw new \RuntimeException('Default theme not available', 0, $e);
            }
            return;
        }

        $app = $ctx->appConfig['app'] ?? [];
        if (!empty($app['view_sanity_strict'])) {
            $viewsDir = rtrim($ctx->rootPath, '/\\') . '/themes/default/views';
            if (!is_dir($viewsDir)) {
                if ($ctx->debug) {
                    throw new \RuntimeException('Default theme views directory missing');
                }
            }
        }
    }
}
