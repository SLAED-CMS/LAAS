<?php
declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;

final class PerformanceCollector implements CollectorInterface
{
    public function collect(Request $request, Response $response, DevToolsContext $context): void
    {
        $context->setResponse([
            'status' => $response->getStatus(),
            'content_type' => $response->getHeader('Content-Type') ?? '',
        ]);
        $context->finalize();
    }
}
