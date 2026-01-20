<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class PingController
{
    public function __construct(private ?View $view = null)
    {
    }

    public function ping(Request $request): Response
    {
        return ApiResponse::ok(['status' => 'ok']);
    }
}
