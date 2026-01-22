<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

interface BootstrapperInterface
{
    public function boot(BootContext $ctx): void;
}
