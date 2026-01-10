<?php
declare(strict_types=1);

namespace Laas\View;

use Laas\Http\Response;

interface RenderAdapterInterface
{
    public function render(string $template, array|ViewModelInterface $data): Response;

    public function renderPartial(string $template, array|ViewModelInterface $data): Response;
}
