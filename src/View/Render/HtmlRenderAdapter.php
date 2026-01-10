<?php
declare(strict_types=1);

namespace Laas\View\Render;

use Laas\Http\Response;
use Laas\View\RenderAdapterInterface;
use Laas\View\View;
use Laas\View\ViewModelInterface;

final class HtmlRenderAdapter implements RenderAdapterInterface
{
    public function __construct(private View $view)
    {
    }

    public function render(string $template, array|ViewModelInterface $data): Response
    {
        return $this->view->render($template, $data);
    }

    public function renderPartial(string $template, array|ViewModelInterface $data): Response
    {
        return $this->view->render($template, $data, 200, [], [
            'render_partial' => true,
        ]);
    }
}
