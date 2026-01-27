<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

use Laas\Core\Container\Container;

final class BootContext
{
    /**
     * @param array<string, mixed> $appConfig
     *
     * BootContext exposes only appConfig. Full config is not available in bootstraps by design.
     */
    public function __construct(
        public readonly string $rootPath,
        public readonly Container $container,
        public readonly array $appConfig,
        public readonly bool $debug
    ) {
    }
}
