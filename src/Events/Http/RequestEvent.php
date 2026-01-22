<?php

declare(strict_types=1);

namespace Laas\Events\Http;

use Laas\Http\Request;

final class RequestEvent
{
    public function __construct(public Request $request)
    {
    }
}
