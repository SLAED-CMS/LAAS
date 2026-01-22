<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class SecurityBootstrap implements BootstrapperInterface
{
    public function boot(BootContext $ctx): void
    {
        $ctx->container->singleton('boot.security', static fn (): bool => true);
    }
}
