<?php
declare(strict_types=1);

namespace Laas\Modules\Api\Controller;

use Laas\Api\ApiResponse;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;

final class PingController
{
    public function __construct(private ?DatabaseManager $db = null)
    {
    }

    public function ping(Request $request): Response
    {
        return ApiResponse::ok(['status' => 'ok']);
    }
}
