<?php

declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Support\RequestScope;

final class ModulesDiscoveryCollector implements CollectorInterface
{
    public function collect(Request $request, Response $response, DevToolsContext $context): void
    {
        $stats = RequestScope::get('devtools.modules');
        $context->setModules(is_array($stats) ? $stats : []);
        $meta = RequestScope::get('devtools.modules_meta');
        $context->setModulesMeta(is_array($meta) ? $meta : []);
    }
}
