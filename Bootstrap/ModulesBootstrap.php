<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

use Laas\Events\EventDispatcherInterface;
use Laas\Modules\ModuleBootstrapAdapter;
use Laas\Modules\ModuleInterface;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\ModulesLoader;
use Laas\Routing\Router;
use Psr\Log\LoggerInterface;

final class ModulesBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        $ctx->container->singleton('boot.modules', static fn (): bool => true);
        $appConfig = $ctx->appConfig['app'] ?? [];
        $enabled = (bool) ($appConfig['bootstraps_modules_takeover'] ?? false);
        if (!$enabled) {
            return;
        }

        $logger = $this->resolveLogger($ctx);
        $router = $this->resolveRouter($ctx);
        if (!$router instanceof Router) {
            $this->log($logger, 'Modules bootstrap skipped: Router missing.');
            return;
        }

        $modulesLoader = $this->resolveModulesLoader($ctx);
        if ($modulesLoader === null || !method_exists($modulesLoader, 'loadEnabledModules')) {
            $this->log($logger, 'Modules bootstrap skipped: ModulesLoader missing.');
            return;
        }

        $events = null;
        try {
            $events = $ctx->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            $events = null;
        }
        if (!$events instanceof EventDispatcherInterface) {
            $events = null;
        }

        try {
            $modules = $modulesLoader->loadEnabledModules();
        } catch (\Throwable $e) {
            $this->log($logger, 'Modules bootstrap failed to load modules.', $e);
            return;
        }

        foreach ($modules as $module) {
            try {
                ModuleBootstrapAdapter::callRegisterBindingsIfSupported($module, $ctx->container);
            } catch (\Throwable $e) {
                $this->log($logger, 'Module bootstrap bindings failed.', $e, ['module' => $module::class]);
            }

            if ($module instanceof ModuleLifecycleInterface) {
                try {
                    ModuleBootstrapAdapter::callRegisterRoutesIfSupported($module, $router);
                } catch (\Throwable $e) {
                    $this->log($logger, 'Module bootstrap routes failed.', $e, ['module' => $module::class]);
                }
            } elseif ($module instanceof ModuleInterface) {
                try {
                    $module->registerRoutes($router);
                } catch (\Throwable $e) {
                    $this->log($logger, 'Module bootstrap legacy routes failed.', $e, ['module' => $module::class]);
                }
            }

            if ($events instanceof EventDispatcherInterface) {
                try {
                    ModuleBootstrapAdapter::callRegisterListenersIfSupported($module, $events);
                } catch (\Throwable $e) {
                    $this->log($logger, 'Module bootstrap listeners failed.', $e, ['module' => $module::class]);
                }
            }
        }
    }

    private function resolveModulesLoader(BootContext $ctx): ?object
    {
        try {
            $loader = $ctx->container->get(ModulesLoader::class);
        } catch (\Throwable) {
            $loader = null;
        }

        if (is_object($loader)) {
            return $loader;
        }

        return null;
    }

    private function resolveRouter(BootContext $ctx): ?Router
    {
        try {
            $router = $ctx->container->get(Router::class);
        } catch (\Throwable) {
            $router = null;
        }

        return $router instanceof Router ? $router : null;
    }

    private function resolveLogger(BootContext $ctx): ?LoggerInterface
    {
        try {
            $logger = $ctx->container->get(LoggerInterface::class);
        } catch (\Throwable) {
            $logger = null;
        }

        return $logger instanceof LoggerInterface ? $logger : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(?LoggerInterface $logger, string $message, ?\Throwable $e = null, array $context = []): void
    {
        if ($logger === null) {
            return;
        }

        if ($e !== null) {
            $context['exception'] = $e;
        }

        $logger->error($message, $context);
    }
}
