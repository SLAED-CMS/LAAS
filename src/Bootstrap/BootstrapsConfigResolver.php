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

        $tokenMap = [
            'security' => SecurityBootstrap::class,
            'observability' => ObservabilityBootstrap::class,
            'modules' => ModulesBootstrap::class,
            'routing' => RoutingBootstrap::class,
            'view' => ViewBootstrap::class,
        ];
        $classMap = array_flip($tokenMap);

        $bootstraps = [];
        foreach ($configured as $name) {
            $value = (string) $name;
            if (str_contains($value, '\\')) {
                $fqcn = ltrim($value, '\\');
                if (isset($classMap[$fqcn])) {
                    $bootstraps[] = new $fqcn();
                }
                continue;
            }

            $key = strtolower($value);
            if (isset($tokenMap[$key])) {
                $fqcn = $tokenMap[$key];
                $bootstraps[] = new $fqcn();
            }
        }

        return $bootstraps;
    }
}
