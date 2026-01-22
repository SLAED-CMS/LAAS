<?php
declare(strict_types=1);

use Laas\Core\Kernel;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\Http\RequestEvent;
use Laas\Events\Http\ResponseEvent;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;

final class HttpEventsSmokeTest extends TestCase
{
    public function testRequestAndResponseEventsFire(): void
    {
        $kernel = new Kernel(dirname(__DIR__, 2));
        $dispatcher = $kernel->container()->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $flags = [
            'request' => false,
            'response' => false,
        ];

        $dispatcher->addListener(RequestEvent::class, static function (RequestEvent $event) use (&$flags): void {
            $flags['request'] = true;
        });
        $dispatcher->addListener(ResponseEvent::class, static function (ResponseEvent $event) use (&$flags): void {
            $flags['response'] = true;
        });

        $kernel->handle(new Request('GET', '/health', [], [], [], ''));
        $this->assertTrue($flags['request']);
        $this->assertTrue($flags['response']);
    }
}
