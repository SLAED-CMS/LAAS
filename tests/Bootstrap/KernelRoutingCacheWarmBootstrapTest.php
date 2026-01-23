<?php

declare(strict_types=1);

use Laas\Core\Bindings\BindingsContext;
use Laas\Core\Container\Container;
use Laas\Core\Kernel;
use Laas\Events\EventDispatcherInterface;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Modules\ModuleLifecycleInterface;
use Laas\Modules\ModulesLoader;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class KernelRoutingCacheWarmBootstrapTest extends TestCase
{
    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testRoutingCacheWarmRunsWithBootstraps(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);
        $this->enableBootstraps($kernel, $root);

        $module = new KernelRoutingCacheWarmBootstrapTestModule();
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

        $cacheFile = $root . '/storage/cache/routes.php';
        $fingerprintFile = $root . '/storage/cache/routes.sha1';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
        if (is_file($fingerprintFile)) {
            @unlink($fingerprintFile);
        }

        $response = $kernel->handle(new Request('GET', '/_boot-cache', [], [], [], ''));

        $this->assertSame(200, $response->getStatus());
        $this->assertFileExists($cacheFile);
        $this->assertFileExists($fingerprintFile);
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
        $config['app']['routing_cache_warm'] = true;
        $config['app']['routing_cache_warm_force'] = true;
        $config['app']['debug'] = true;
        $prop->setValue($kernel, $config);

        BindingsContext::set($kernel, $config, $root);
    }
}

final class KernelRoutingCacheWarmBootstrapTestModule implements ModuleLifecycleInterface
{
    public function registerBindings(Container $container): void
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->addRoute('GET', '/_boot-cache', [KernelRoutingCacheWarmBootstrapTest::class, 'handle']);
    }

    public function registerListeners(EventDispatcherInterface $events): void
    {
    }
}
