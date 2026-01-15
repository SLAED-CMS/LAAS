<?php
declare(strict_types=1);

namespace Laas\Modules\DemoBlog\Controller;

use Laas\Database\DatabaseManager;
use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class DemoBlogPingController
{
    public function __construct(
        private View $view,
        private ?DatabaseManager $db = null
    ) {
    }

    public function ping(Request $request, array $params = []): Response
    {
        return Response::json([
            'status' => 'ok',
            'module' => 'demoblog',
        ], 200);
    }
}
