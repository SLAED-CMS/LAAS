<?php

declare(strict_types=1);

use Laas\Bootstrap\BootstrapsConfigResolver;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Bootstrap\RoutingBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ViewBootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapsConfigResolverTest extends TestCase
{
    public function testDisabledReturnsEmpty(): void
    {
        $resolver = new BootstrapsConfigResolver();

        $result = $resolver->resolve([], false);

        $this->assertSame([], $this->classesOf($result));
    }

    public function testDefaultsWhenMissingList(): void
    {
        $resolver = new BootstrapsConfigResolver();

        $result = $resolver->resolve([], true);

        $this->assertSame($this->defaultOrder(), $this->classesOf($result));
    }

    public function testDefaultsWhenListEmpty(): void
    {
        $resolver = new BootstrapsConfigResolver();

        $result = $resolver->resolve(['bootstraps' => []], true);

        $this->assertSame($this->defaultOrder(), $this->classesOf($result));
    }

    public function testListRespectsOrderAndIgnoresUnknown(): void
    {
        $resolver = new BootstrapsConfigResolver();

        $result = $resolver->resolve([
            'bootstraps' => ['view', 'unknown', 'security', 'modules'],
        ], true);

        $this->assertSame([
            ViewBootstrap::class,
            SecurityBootstrap::class,
            ModulesBootstrap::class,
        ], $this->classesOf($result));
    }

    /**
     * @param list<\Laas\Bootstrap\BootstrapperInterface> $bootstraps
     * @return list<class-string>
     */
    private function classesOf(array $bootstraps): array
    {
        $names = [];
        foreach ($bootstraps as $bootstrap) {
            $names[] = $bootstrap::class;
        }

        return $names;
    }

    /**
     * @return list<class-string>
     */
    private function defaultOrder(): array
    {
        return [
            SecurityBootstrap::class,
            ObservabilityBootstrap::class,
            ModulesBootstrap::class,
            RoutingBootstrap::class,
            ViewBootstrap::class,
        ];
    }
}
