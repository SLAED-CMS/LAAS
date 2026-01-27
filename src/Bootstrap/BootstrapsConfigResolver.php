<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class BootstrapsConfigResolver
{
    /**
     * @return array<string, class-string>
     */
    public static function tokenMap(): array
    {
        return [
            'security' => SecurityBootstrap::class,
            'observability' => ObservabilityBootstrap::class,
            'modules' => ModulesBootstrap::class,
            'routing' => RoutingBootstrap::class,
            'view' => ViewBootstrap::class,
        ];
    }

    /**
     * @param array<string, mixed> $fullConfig
     * @return list<BootstrapperInterface>
     */
    public function resolve(array $fullConfig, bool $bootEnabled): array
    {
        if (!$bootEnabled) {
            return [];
        }

        $appConfig = $fullConfig['app'] ?? [];
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

        $tokenMap = self::tokenMap();
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
