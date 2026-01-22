<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class BootstrapsRunner
{
    /** @var list<BootstrapperInterface> */
    private array $bootstraps;

    /**
     * @param list<BootstrapperInterface> $bootstraps
     */
    public function __construct(array $bootstraps = [])
    {
        $this->bootstraps = $bootstraps;
    }

    public function run(BootContext $ctx): void
    {
        foreach ($this->bootstraps as $bootstrap) {
            $bootstrap->boot($ctx);
        }
    }

    public function count(): int
    {
        return count($this->bootstraps);
    }
}
