<?php

declare(strict_types=1);

use Laas\Core\Bindings\BindingsContext;
use Laas\Core\Kernel;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class KernelDefaultPathTest extends TestCase
{
    public function testDefaultPathKeepsRequestIdHeader(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new Kernel($root);
        $this->configureApp($kernel, $root, [
            'bootstraps_enabled' => false,
            'bootstraps_modules_takeover' => false,
            'debug' => true,
        ]);

        $requestId = 'abc12345';
        $request = new Request('GET', '/api/v1/ping', [], [], ['x-request-id' => $requestId], '');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame($requestId, $response->getHeader('X-Request-Id'));
        $this->assertNull($response->getHeader('X-Response-Time-Ms'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureApp(Kernel $kernel, string $root, array $overrides): void
    {
        $ref = new ReflectionClass($kernel);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $config = $prop->getValue($kernel);
        if (!is_array($config)) {
            $config = [];
        }
        $config['app'] = array_merge($config['app'] ?? [], $overrides);
        $prop->setValue($kernel, $config);

        BindingsContext::set($kernel, $config, $root);
    }
}
