<?php
declare(strict_types=1);

namespace Laas\Modules\Admin\Controller;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class DashboardController
{
    public function __construct(private View $view)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view->render('pages/dashboard.html', [], 200, [], [
            'theme' => 'admin',
        ]);
    }
}
