<?php

declare(strict_types=1);

namespace Laas\Http\Middleware;

use Laas\Http\Request;
use Laas\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, callable $next): Response;
}
