<?php
declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\RequestScope;

final class PerformanceCollector implements CollectorInterface
{
    public function collect(Request $request, Response $response, DevToolsContext $context): void
    {
        $errorSource = RequestScope::get('error.source');
        $errorCode = RequestScope::get('error.code');
        $context->setResponse([
            'status' => $response->getStatus(),
            'content_type' => $response->getHeader('Content-Type') ?? '',
            'error_source' => is_string($errorSource) ? $errorSource : '',
            'error_code' => is_string($errorCode) ? $errorCode : '',
        ]);
        $context->finalize();
    }
}
