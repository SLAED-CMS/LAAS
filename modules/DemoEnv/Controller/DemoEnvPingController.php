<?php
declare(strict_types=1);

namespace Laas\Modules\DemoEnv\Controller;

use Laas\Api\ApiResponse;
use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class DemoEnvPingController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function ping(Request $request, array $params = []): Response
    {
        return ApiResponse::ok([
            'ok' => true,
            'module' => 'demoenv',
            'action' => 'ping',
            'ts' => gmdate(DATE_ATOM),
        ]);
    }
}
