<?php

declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Http\Request;
use Laas\Http\Response;

final class EchoController
{
    public function post(Request $request): Response
    {
        $msg = $request->post('msg') ?? '';
        $escaped = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Response('OK: ' . $escaped, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
