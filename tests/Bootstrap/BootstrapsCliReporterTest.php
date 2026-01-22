<?php

declare(strict_types=1);

use Laas\Bootstrap\BootstrapsCliReporter;
use Laas\Bootstrap\BootstrapsConfigResolver;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Bootstrap\RoutingBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ViewBootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapsCliReporterTest extends TestCase
{
    public function testDumpIncludesFlagsAndOrder(): void
    {
        $resolver = new BootstrapsConfigResolver();
        $bootstraps = $resolver->resolve([
            'bootstraps' => ['security', 'observability', 'modules', 'routing', 'view'],
        ], true);

        $output = BootstrapsCliReporter::formatDump([
            'bootstraps_enabled' => true,
            'bootstraps_modules_takeover' => false,
            'routing_cache_warm' => true,
            'routing_cache_warm_force' => false,
            'view_sanity_strict' => false,
        ], $bootstraps);

        $this->assertStringContainsString("bootstraps_enabled=1\n", $output);
        $this->assertStringContainsString("routing_cache_warm=1\n", $output);
        $this->assertStringContainsString("bootstraps:\n", $output);

        $expected = [
            '- security ' . SecurityBootstrap::class,
            '- observability ' . ObservabilityBootstrap::class,
            '- modules ' . ModulesBootstrap::class,
            '- routing ' . RoutingBootstrap::class,
            '- view ' . ViewBootstrap::class,
        ];
        $lines = array_values(array_filter(explode("\n", $output)));
        $start = array_search('bootstraps:', $lines, true);
        $actual = array_slice($lines, $start + 1, count($expected));

        $this->assertSame($expected, $actual);
    }
}
