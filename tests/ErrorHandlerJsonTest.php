<?php
declare(strict_types=1);

use Laas\Http\Middleware\ErrorHandlerMiddleware;
use Laas\Http\Request;
use Laas\Support\RequestScope;
use Laas\DevTools\DevToolsContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ErrorHandlerJsonTest extends TestCase
{
    public function testJsonErrorUsesEnvelope(): void
    {
        $logger = new ErrorSpyLogger();
        $middleware = new ErrorHandlerMiddleware($logger, true, 'req-1');
        $request = new Request('GET', '/api/test', [], [], ['accept' => 'application/json'], '');

        RequestScope::setRequest($request);
        RequestScope::set('devtools.context', new DevToolsContext(['enabled' => true, 'request_id' => 'req-1']));

        $response = $middleware->process($request, static function (): never {
            throw new RuntimeException('Boom');
        });

        $this->assertSame(500, $response->getStatus());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('E_INTERNAL', $data['error']['code'] ?? null);
        $this->assertArrayHasKey('message', $data['error'] ?? []);
        $this->assertSame('req-1', $data['meta']['request_id'] ?? null);
        $this->assertArrayHasKey('ts', $data['meta'] ?? []);
        $this->assertNotEmpty($logger->lastContext['error_id'] ?? null);

        RequestScope::reset();
        RequestScope::setRequest(null);
    }
}

final class ErrorSpyLogger implements LoggerInterface
{
    public array $lastContext = [];

    public function emergency($message, array $context = []): void {}
    public function alert($message, array $context = []): void {}
    public function critical($message, array $context = []): void {}
    public function error($message, array $context = []): void { $this->lastContext = $context; }
    public function warning($message, array $context = []): void {}
    public function notice($message, array $context = []): void {}
    public function info($message, array $context = []): void {}
    public function debug($message, array $context = []): void {}
    public function log($level, $message, array $context = []): void {}
}
