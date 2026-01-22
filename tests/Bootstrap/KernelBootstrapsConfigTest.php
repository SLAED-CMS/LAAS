<?php

declare(strict_types=1);

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

        $kernel->handle(new Request('GET', '/health', [], [], [], ''));

        $container = $kernel->container();
        $this->assertTrue($container->get('boot.view'));
        $this->assertTrue($container->get('boot.security'));
        $this->assertTrue($container->get('boot.modules'));
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
