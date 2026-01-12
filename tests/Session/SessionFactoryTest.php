<?php
declare(strict_types=1);

use Laas\Session\NativeSession;
use Laas\Session\SessionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SessionFactoryTest extends TestCase
{
    public function testNativeDriverReturnsNativeSession(): void
    {
        $factory = new SessionFactory([
            'driver' => 'native',
        ], new NullLogger());

        $this->assertInstanceOf(NativeSession::class, $factory->create());
    }

    public function testRedisDriverFallsBackToNativeOnConnectFailure(): void
    {
        $factory = new SessionFactory([
            'driver' => 'redis',
            'redis' => [
                'url' => 'redis://127.0.0.1:1/0',
                'timeout' => 0.01,
                'prefix' => 'laas:sess:',
            ],
        ], new NullLogger());

        $this->assertInstanceOf(NativeSession::class, $factory->create());
    }
}
