<?php
declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Api\ApiResponse;
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
            $this->logger->error($e->getMessage(), [
                'exception' => $e,
                'request_id' => $this->requestId,
            ]);

            if (str_starts_with($request->getPath(), '/api/')) {
                $details = [
                    'request_id' => $this->requestId,
                ];

                if ($this->debug) {
                    $details['message'] = $e->getMessage();
                    $details['trace'] = $e->getTraceAsString();
                }

                return ApiResponse::error('internal_error', 'Internal Server Error', $details, 500)
                    ->withHeader('X-Request-Id', $this->requestId);
            }

            if ($request->expectsJson()) {
                $payload = [
                    'error' => 'internal_error',
                    'request_id' => $this->requestId,
                ];

                if ($this->debug) {
                    $payload['message'] = $e->getMessage();
                    $payload['trace'] = $e->getTraceAsString();
                }

                return Response::json($payload, 500)
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
}
