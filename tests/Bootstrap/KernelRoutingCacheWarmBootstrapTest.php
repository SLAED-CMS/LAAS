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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class KernelRoutingCacheWarmBootstrapTest extends TestCase
{
    private string $root;
    private string $cacheFile;
    private string $fingerprintFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->cacheFile = $this->root . '/storage/cache/routes.php';
        $this->fingerprintFile = $this->root . '/storage/cache/routes.sha1';

        // Clear route cache files and PHP caches
        $this->clearCacheFiles();
    }

    protected function tearDown(): void
    {
        $this->clearCacheFiles();
        parent::tearDown();
    }

    private function clearCacheFiles(): void
    {
        @unlink($this->cacheFile);
        @unlink($this->fingerprintFile);

        // Clear PHP's file stat cache
        clearstatcache(true, $this->cacheFile);
        clearstatcache(true, $this->fingerprintFile);
        clearstatcache(true);

        // Clear opcache if available (prevents PHP from caching stale route data)
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->cacheFile, true);
            @opcache_invalidate($this->fingerprintFile, true);
        }
    }

    public static function handle(Request $request, array $vars = []): Response
    {
        return new Response('ok', 200);
    }

    public function testRoutingCacheWarmRunsWithBootstraps(): void
    {
        $this->clearCacheFiles();
        $this->assertFileDoesNotExist($this->cacheFile, 'Cache file should not exist before test');

        $kernel = new Kernel($this->root);
        $this->enableBootstraps($kernel, $this->root);

        $loader = new class() {
            /** @return list<object> */
            public function loadEnabledModules(): array
            {
                return [new KernelRoutingCacheWarmBootstrapTestModule()];
            }
        };

        $kernel->container()->singleton(ModulesLoader::class, static fn () => $loader);

        $response = $kernel->handle(new Request('GET', '/_boot-cache', [], [], [], ''));

        $this->assertSame(200, $response->getStatus(), 'Route registered via bootstrap should return 200');
        $this->assertFileExists($this->cacheFile, 'Route cache file should be created');
        $this->assertFileExists($this->fingerprintFile, 'Route fingerprint file should be created');
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
        $config['app']['bootstraps'] = ['modules', 'routing'];
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
