<?php
declare(strict_types=1);

use Laas\Http\Middleware\ErrorHandlerMiddleware;
use Laas\Http\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ErrorHandlerJsonTest extends TestCase
{
    public function testJsonErrorUsesProblemDetails(): void
    {
        $logger = new ErrorSpyLogger();
        $middleware = new ErrorHandlerMiddleware($logger, true, 'req-1');
        $request = new Request('GET', '/api/test', [], [], ['accept' => 'application/json'], '');

        $response = $middleware->process($request, static function (): never {
            throw new RuntimeException('Boom');
        });

        $this->assertSame(500, $response->getStatus());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('Internal Server Error', $data['title'] ?? null);
        $this->assertSame(500, $data['status'] ?? null);
        $this->assertSame('/api/test', $data['instance'] ?? null);
        $this->assertArrayHasKey('error_id', $data);
        $this->assertArrayNotHasKey('trace', $data);
        $this->assertArrayNotHasKey('message', $data);
        $this->assertNotEmpty($logger->lastContext['error_id'] ?? null);
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
