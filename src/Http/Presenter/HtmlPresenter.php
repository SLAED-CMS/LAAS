<?php

declare(strict_types=1);

namespace Laas\Http\Presenter;

use Laas\Http\Response;
use Laas\View\View;

final class HtmlPresenter implements PresenterInterface
{
    public function __construct(
        private View $view,
        private string $template
    ) {
    }

    public function present(array $data, array $meta = []): Response
    {
        return $this->view->render($this->template, $data);
    }
}
