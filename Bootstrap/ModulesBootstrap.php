<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class ModulesBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        $ctx->container->singleton('boot.modules', static fn (): bool => true);
    }
}
