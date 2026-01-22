<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class BootstrapsConfigResolver
{
    /**
     * @param array<string, mixed> $appConfig
     * @return list<BootstrapperInterface>
     */
    public function resolve(array $appConfig, bool $bootEnabled): array
    {
        if (!$bootEnabled) {
            return [];
        }

        $configured = $appConfig['bootstraps'] ?? [];
        if (!is_array($configured) || $configured === []) {
            return [
                new SecurityBootstrap(),
                new ObservabilityBootstrap(),
                new ModulesBootstrap(),
                new RoutingBootstrap(),
                new ViewBootstrap(),
            ];
        }

        $bootstraps = [];
        foreach ($configured as $name) {
            $key = strtolower((string) $name);
            switch ($key) {
                case 'security':
                    $bootstraps[] = new SecurityBootstrap();
                    break;
                case 'observability':
                    $bootstraps[] = new ObservabilityBootstrap();
                    break;
                case 'modules':
                    $bootstraps[] = new ModulesBootstrap();
                    break;
                case 'routing':
                    $bootstraps[] = new RoutingBootstrap();
                    break;
                case 'view':
                    $bootstraps[] = new ViewBootstrap();
                    break;
            }
        }

        return $bootstraps;
    }
}
