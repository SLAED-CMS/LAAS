<?php
declare(strict_types=1);

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchMatchesRoute(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/ping', function (): Response {
            return new Response('ok', 200);
        });

        $request = new Request('GET', '/ping', [], [], [], '');
        $response = $router->dispatch($request);

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame('ok', $this->getBody($response));
    }

    public function testDispatchReturnsNotFound(): void
    {
        $router = new Router();
        $request = new Request('GET', '/missing', [], [], [], '');
        $response = $router->dispatch($request);

        $this->assertSame(404, $this->getStatus($response));
    }

    public function testDispatchReturnsMethodNotAllowed(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/ping', function (): Response {
            return new Response('ok', 200);
        });

        $request = new Request('GET', '/ping', [], [], [], '');
        $response = $router->dispatch($request);

        $this->assertSame(405, $this->getStatus($response));
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionProperty($response, 'status');
        $ref->setAccessible(true);

        return (int) $ref->getValue($response);
    }

    private function getBody(Response $response): string
    {
        $ref = new \ReflectionProperty($response, 'body');
        $ref->setAccessible(true);

        return (string) $ref->getValue($response);
    }
}
