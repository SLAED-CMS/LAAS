<?php
declare(strict_types=1);

use Laas\Core\Bindings\BindingsContext;
use Laas\Core\Kernel;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\Http\ResponseEvent;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\ModulesLoader;
use Laas\Routing\Router;
use Laas\Core\Container\Container;
use PHPUnit\Framework\TestCase;

final class ModulesBootstrapTakeoverKernelTest extends TestCase
{
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testKernelTakeoverRunsModuleBindingsRoutesAndListeners(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);
        $this->enableBootstraps($kernel, $root);

        $module = new class implements ModuleLifecycleInterface {
            public function registerBindings(Container $container): void
            {
                $container->singleton('test.bound', static fn (): bool => true);
            }

            public function registerRoutes(Router $router): void
            {
                $router->addRoute('GET', '/_boot-test', [ModulesBootstrapTakeoverKernelTest::class, 'handle']);
            }

            public function registerListeners(EventDispatcherInterface $events): void
            {
                $events->addListener(ResponseEvent::class, static function (ResponseEvent $event): void {
                    $event->response = $event->response->withHeader('X-Boot-Test', 'yes');
                });
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

        $kernel->container()->singleton(ModulesLoader::class, static fn () => $loader);

        $response = $kernel->handle(new Request('GET', '/_boot-test', [], [], [], ''));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('yes', $response->getHeader('X-Boot-Test'));
        $this->assertTrue($kernel->container()->get('test.bound'));
    }

    private function enableBootstraps(Kernel $kernel, string $root): void
    {
        $ref = new ReflectionClass($kernel);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $config = $prop->getValue($kernel);
        if (!is_array($config)) {
            $config = [];
        }
        $config['app'] = $config['app'] ?? [];
        $config['app']['bootstraps_enabled'] = true;
        $config['app']['bootstraps_modules_takeover'] = true;
        $config['app']['debug'] = true;
        $prop->setValue($kernel, $config);

        BindingsContext::set($kernel, $config, $root);
    }
}
