<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\BootstrapperInterface;
use Laas\Bootstrap\BootstrapsRunner;
use Laas\Core\Container\Container;
use PHPUnit\Framework\TestCase;

final class BootstrapsRunnerTest extends TestCase
{
    public function testEmptyRunnerDoesNothing(): void
    {
        $runner = new BootstrapsRunner();
        $ctx = new BootContext(__DIR__, new Container(), [], false);

        $runner->run($ctx);

        $this->assertSame(0, $runner->count());
    }

    public function testRunnerInvokesBootstrap(): void
    {
        $state = (object) ['called' => false];
        $bootstrap = new class($state) implements BootstrapperInterface {
            public function __construct(private object $state)
            {
            }

            public function boot(BootContext $ctx): void
            {
                $this->state->called = true;
            }
        };

        $runner = new BootstrapsRunner([$bootstrap]);
        $ctx = new BootContext(__DIR__, new Container(), [], false);

        $runner->run($ctx);

        $this->assertTrue($state->called);
        $this->assertSame(1, $runner->count());
    }
}
