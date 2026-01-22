<?php

declare(strict_types=1);

namespace Laas\Bootstrap;

final class BootstrapsCliReporter
{
    /**
     * @param array{bootstraps_enabled: bool, bootstraps_modules_takeover: bool, routing_cache_warm: bool, routing_cache_warm_force: bool, view_sanity_strict: bool} $flags
     * @param list<BootstrapperInterface> $bootstraps
     * @return string
     */
    public static function formatDump(array $flags, array $bootstraps): string
    {
        $lines = [
            'bootstraps_enabled=' . self::bool($flags['bootstraps_enabled']),
            'bootstraps_modules_takeover=' . self::bool($flags['bootstraps_modules_takeover']),
            'routing_cache_warm=' . self::bool($flags['routing_cache_warm']),
            'routing_cache_warm_force=' . self::bool($flags['routing_cache_warm_force']),
            'view_sanity_strict=' . self::bool($flags['view_sanity_strict']),
            'bootstraps:',
        ];

        $tokenMap = BootstrapsConfigResolver::tokenMap();
        $classMap = array_flip($tokenMap);

        if ($bootstraps === []) {
            $lines[] = '- (none)';
            return implode("\n", $lines) . "\n";
        }

        foreach ($bootstraps as $bootstrap) {
            $fqcn = $bootstrap::class;
            $token = $classMap[$fqcn] ?? null;
            $lines[] = $token !== null ? '- ' . $token . ' ' . $fqcn : '- ' . $fqcn;
        }

        return implode("\n", $lines) . "\n";
    }

    private static function bool(bool $value): string
    {
        return $value ? '1' : '0';
    }
}
