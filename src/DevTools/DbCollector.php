<?php

declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;

final class DbCollector implements CollectorInterface
{
    public function collect(Request $request, Response $response, DevToolsContext $context): void
    {
        // DB queries are collected via ProxyPDO. This collector is a no-op placeholder.
    }
}
