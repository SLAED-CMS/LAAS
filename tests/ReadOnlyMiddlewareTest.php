<?php
declare(strict_types=1);

use Laas\Http\Middleware\ReadOnlyMiddleware;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class ReadOnlyMiddlewareTest extends TestCase
{
    public function testReadOnlyBlocksPost(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('POST', '/admin/media/upload', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(503, $response->getStatus());
    }

    public function testReadOnlyAllowsGet(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('GET', '/admin/media', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }

    public function testReadOnlyAllowsLogin(): void
    {
        $translator = new Translator(dirname(__DIR__), 'default', 'en');
        $middleware = new ReadOnlyMiddleware(true, $translator);

        $request = new Request('POST', '/login', [], [], [], '');
        $response = $middleware->process($request, static fn (Request $req): Response => new Response('OK', 200));

        $this->assertSame(200, $response->getStatus());
    }
}
