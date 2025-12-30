<?php
declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\Security\Csrf;

final class CsrfController
{
    public function get(Request $request): Response
    {
        $token = (new Csrf())->getToken();

        return Response::json(['token' => $token]);
    }
}
