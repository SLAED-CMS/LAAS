<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Http\Request;
use Laas\Http\Response;

final class PingController
{
    public function ping(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
