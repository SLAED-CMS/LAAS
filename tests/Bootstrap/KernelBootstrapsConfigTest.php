<?php

declare(strict_types=1);

use Laas\Bootstrap\ModulesBootstrap;
use Laas\Bootstrap\SecurityBootstrap;
use Laas\Bootstrap\ViewBootstrap;
use Laas\Core\Bindings\BindingsContext;
use Laas\Core\Container\Container;
use Laas\Core\Kernel;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class KernelBootstrapsConfigTest extends TestCase
{
    public function testBootstrapsDisabledRunsNone(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);
        $config = $this->readKernelConfig($kernel);
        $config['app']['bootstraps_enabled'] = false;
        $this->setKernelConfig($kernel, $config, $root);

        $kernel->handle(new Request('GET', '/health', [], [], [], ''));

        $container = $kernel->container();
        $this->assertBootFlagMissing($container, 'boot.security');
        $this->assertBootFlagMissing($container, 'boot.modules');
        $this->assertBootFlagMissing($container, 'boot.view');
    }

    public function testBootstrapsListRespectsOrderAndIgnoresUnknown(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);
        $config = $this->readKernelConfig($kernel);
        $config['app']['bootstraps_enabled'] = true;
        $config['app']['bootstraps_modules_takeover'] = false;
        $config['app']['bootstraps'] = ['view', 'security', 'modules', 'unknown'];
        $this->setKernelConfig($kernel, $config, $root);

        $built = $this->invokeBuildBootstraps($kernel, $config['app']);
        $this->assertSame([
            ViewBootstrap::class,
            SecurityBootstrap::class,
            ModulesBootstrap::class,
        ], $built);

        $kernel->handle(new Request('GET', '/health', [], [], [], ''));

        $container = $kernel->container();
        $this->assertTrue($container->get('boot.view'));
        $this->assertTrue($container->get('boot.security'));
        $this->assertTrue($container->get('boot.modules'));
    }

    /**
     * @return list<class-string>
     */
    private function invokeBuildBootstraps(Kernel $kernel, array $appConfig): array
    {
        $ref = new ReflectionClass($kernel);
        $method = $ref->getMethod('buildBootstraps');
        $method->setAccessible(true);
        $bootstraps = $method->invoke($kernel, $appConfig);
        $names = [];
        foreach ($bootstraps as $bootstrap) {
            $names[] = $bootstrap::class;
        }

        return $names;
    }

    private function assertBootFlagMissing(Container $container, string $key): void
    {
        try {
            $container->get($key);
            $this->fail('Boot flag was set: ' . $key);
        } catch (Throwable) {
            $this->assertTrue(true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readKernelConfig(Kernel $kernel): array
    {
        $ref = new ReflectionClass($kernel);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $config = $prop->getValue($kernel);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setKernelConfig(Kernel $kernel, array $config, string $root): void
    {
        $ref = new ReflectionClass($kernel);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($kernel, $config);
        BindingsContext::set($kernel, $config, $root);
    }
}
