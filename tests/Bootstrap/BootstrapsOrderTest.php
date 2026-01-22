<?php
declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Bootstrap\BootstrapperInterface;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Bootstrap\RoutingBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ViewBootstrap;
use Laas\Core\Container\Container;
use PHPUnit\Framework\TestCase;

final class BootstrapsOrderTest extends TestCase
{
    public function testBootstrapsRunInKernelOrder(): void
    {
        $order = [];
        $runner = new BootstrapsRunner([
            new RecordingBootstrap(SecurityBootstrap::class, $order),
            new RecordingBootstrap(ObservabilityBootstrap::class, $order),
            new RecordingBootstrap(ModulesBootstrap::class, $order),
            new RecordingBootstrap(RoutingBootstrap::class, $order),
            new RecordingBootstrap(ViewBootstrap::class, $order),
        ]);

        $ctx = new BootContext(__DIR__, new Container(), ['app' => []], false);
        $runner->run($ctx);

        $this->assertSame([
            SecurityBootstrap::class,
            ObservabilityBootstrap::class,
            ModulesBootstrap::class,
            RoutingBootstrap::class,
            ViewBootstrap::class,
        ], $order);
    }
}

final class RecordingBootstrap implements BootstrapperInterface
{
    /**
     * @param array<int, string> $order
     */
    public function __construct(private string $name, private array &$order)
    {
    }

    public function boot(BootContext $ctx): void
    {
        $this->order[] = $this->name;
    }
}
