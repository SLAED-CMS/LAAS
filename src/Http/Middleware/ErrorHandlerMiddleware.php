<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ProblemDetails;
use Laas\Http\Request;
use Laas\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

final class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $debug,
        private string $requestId
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            $errorId = $this->generateErrorId();
            $this->logger->error($e->getMessage(), [
                'exception' => $e,
                'request_id' => $this->requestId,
                'error_id' => $errorId,
            ]);

            if ($request->expectsJson()) {
                $problem = ProblemDetails::internalError($request, $errorId);
                return Response::json($problem->toArray(), 500)
                    ->withHeader('X-Request-Id', $this->requestId);
            }

            $message = 'Internal Server Error';
            if ($this->debug) {
                $message .= "\n" . $e->getMessage() . "\n" . $e->getTraceAsString();
            }

            return (new Response($message, 500, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]))->withHeader('X-Request-Id', $this->requestId);
        }
    }

    private function generateErrorId(): string
    {
        return 'ERR-' . strtoupper(bin2hex(random_bytes(6)));
    }
}
