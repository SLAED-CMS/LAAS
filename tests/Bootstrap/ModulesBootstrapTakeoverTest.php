<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\ModulesBootstrap;
use Laas\Core\Container\Container;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\SimpleEventDispatcher;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\ModulesLoader;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class ModulesBootstrapTakeoverTest extends TestCase
{
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testTakeoverRegistersRoutesAndListeners(): void
    {
        $container = new Container();
        $router = new Router(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laas-router-takeover-test', true);
        $container->singleton(Router::class, static fn (): Router => $router);
        $container->singleton(EventDispatcherInterface::class, static fn (): EventDispatcherInterface => new SimpleEventDispatcher());

        $module = new class implements ModuleLifecycleInterface {
            public bool $listenersCalled = false;

            public function registerBindings(Container $container): void
            {
            }

            public function registerRoutes(Router $router): void
            {
                $router->addRoute('GET', '/_boot-test', [ModulesBootstrapTakeoverTest::class, 'handle']);
            }

            public function registerListeners(EventDispatcherInterface $events): void
            {
                $this->listenersCalled = true;
            }
        };

        $loader = new class($module) {
            public function __construct(private object $module)
            {
            }

            /** @return list<object> */
            public function loadEnabledModules(): array
            {
                return [$this->module];
            }
        };

        $container->singleton(ModulesLoader::class, static fn () => $loader);

        $ctxConfig = [
            'app' => [
                'bootstraps_modules_takeover' => true,
            ],
        ];
        $ctx = new BootContext(__DIR__, $container, $ctxConfig, true);

        $bootstrap = new ModulesBootstrap();
        $bootstrap->boot($ctx);

        $response = $router->dispatch(new Request('GET', '/_boot-test', [], [], [], ''));
        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($module->listenersCalled);
    }
}
