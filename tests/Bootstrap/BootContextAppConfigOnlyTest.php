<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Core\Container\Container;
use PHPUnit\Framework\TestCase;

final class BootContextAppConfigOnlyTest extends TestCase
{
    public function testAppConfigOnlyExposed(): void
    {
        $rootPath = '/x';
        $container = new Container();
        $appConfig = [
            'boot' => [
                'security' => true,
            ],
            'debug_toolbar' => true,
        ];
        $debug = true;

        $ctx = new BootContext($rootPath, $container, $appConfig, $debug);

        $this->assertSame($appConfig, $ctx->appConfig);
        $this->assertFalse(method_exists($ctx, 'getConfig'));
        $this->assertFalse(property_exists($ctx, 'config'));
        $this->assertFalse(property_exists($ctx, 'fullConfig'));
    }
}
