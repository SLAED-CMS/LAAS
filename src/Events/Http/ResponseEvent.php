<?php

declare(strict_types=1);

namespace Laas\Events\Http;

use Laas\Http\Request;
use Laas\Http\Response;

final class ResponseEvent
{
    public function __construct(public Request $request, public Response $response)
    {
    }
}
