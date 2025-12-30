<?php
declare(strict_types=1);

namespace Laas\Modules\System\Controller;

use Laas\Http\Request;
use Laas\Http\Response;
use Laas\View\View;

final class HomeController
{
    public function __construct(private View $view)
    {
    }

    public function index(Request $request): Response
    {
        $data = [
            'show_message' => true,
            'items' => [
                ['title' => 'First item'],
                ['title' => 'Second item'],
                ['title' => 'Third item'],
            ],
        ];

        return $this->view->render('pages/home.html', $data);
    }
}
