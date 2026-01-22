<?php

declare(strict_types=1);

use Laas\Bootstrap\BootContext;
use Laas\Bootstrap\ObservabilityBootstrap;
use Laas\Core\Container\Container;
use Laas\Events\EventDispatcherInterface;
use Laas\Events\Http\RequestEvent;
use Laas\Events\Http\ResponseEvent;
use Laas\Events\SimpleEventDispatcher;
use Laas\Http\Request;
use Laas\Http\Response;
use PHPUnit\Framework\TestCase;

final class ObservabilityBootstrapTest extends TestCase
{
    public function testAddsResponseTimeHeaderInDebug(): void
    {
        $container = new Container();
        $container->singleton(EventDispatcherInterface::class, static fn () => new SimpleEventDispatcher());

        $ctx = new BootContext(__DIR__, $container, [], true);
        $bootstrap = new ObservabilityBootstrap();
        $bootstrap->boot($ctx);

        $dispatcher = $container->get(EventDispatcherInterface::class);

        $request = new Request('GET', '/test', [], [], ['x-request-id' => 'test-id'], '');
        $response = new Response('ok', 200, []);

        $dispatcher->dispatch(new RequestEvent($request));
        $responseEvent = new ResponseEvent($request, $response);
        $dispatcher->dispatch($responseEvent);

        $header = $responseEvent->response->getHeader('X-Response-Time-Ms');
        $this->assertIsString($header);
        $this->assertNotSame('', $header);
    }
}
