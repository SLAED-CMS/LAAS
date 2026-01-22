<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\ErrorCode;
use Laas\Http\ErrorResponse;
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

            return ErrorResponse::respondForRequest($request, ErrorCode::INTERNAL, [], 500, [], 'error.handler');
        }
    }

    private function generateErrorId(): string
    {
        return 'ERR-' . strtoupper(bin2hex(random_bytes(6)));
    }
}
