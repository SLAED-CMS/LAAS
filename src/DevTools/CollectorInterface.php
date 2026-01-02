<?php
declare(strict_types=1);

namespace Laas\DevTools;

use Laas\Http\Request;
use Laas\Http\Response;

interface CollectorInterface
{
    public function collect(Request $request, Response $response, DevToolsContext $context): void;
}
