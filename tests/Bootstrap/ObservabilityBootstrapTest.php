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
    public function testAddsRequestIdAndResponseTimeWhenMissing(): void
    {
        $container = new Container();
        $container->singleton(EventDispatcherInterface::class, static fn () => new SimpleEventDispatcher());

        $ctx = new BootContext(__DIR__, $container, ['app' => ['bootstraps_enabled' => true]], true);
        $bootstrap = new ObservabilityBootstrap();
        $bootstrap->boot($ctx);

        $dispatcher = $container->get(EventDispatcherInterface::class);

        $request = new Request('GET', '/test', [], [], [], '');
        $response = new Response('ok', 200, []);

        $requestEvent = new RequestEvent($request);
        $dispatcher->dispatch($requestEvent);
        $request = $requestEvent->request;
        $responseEvent = new ResponseEvent($request, $response);
        $dispatcher->dispatch($responseEvent);

        $requestHeader = $request->getHeader('x-request-id');
        $this->assertIsString($requestHeader);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $requestHeader);

        $responseHeader = $responseEvent->response->getHeader('X-Request-Id');
        $this->assertSame($requestHeader, $responseHeader);

        $header = $responseEvent->response->getHeader('X-Response-Time-Ms');
        $this->assertIsString($header);
        $this->assertNotSame('', $header);
    }

    public function testPreservesProvidedRequestId(): void
    {
        $container = new Container();
        $container->singleton(EventDispatcherInterface::class, static fn () => new SimpleEventDispatcher());

        $ctx = new BootContext(__DIR__, $container, ['app' => ['bootstraps_enabled' => true]], true);
        $bootstrap = new ObservabilityBootstrap();
        $bootstrap->boot($ctx);

        $dispatcher = $container->get(EventDispatcherInterface::class);

        $request = new Request('GET', '/test', [], [], ['x-request-id' => 'abc_DEF-1234'], '');
        $response = new Response('ok', 200, []);

        $requestEvent = new RequestEvent($request);
        $dispatcher->dispatch($requestEvent);
        $responseEvent = new ResponseEvent($requestEvent->request, $response);
        $dispatcher->dispatch($responseEvent);

        $this->assertSame('abc_DEF-1234', $responseEvent->response->getHeader('X-Request-Id'));
    }
}
