<?php

declare(strict_types=1);

namespace Laas\Modules\Admin\ViewModel;

use Laas\View\ViewModelInterface;

final class TablePaginationViewModel implements ViewModelInterface
{
    public function __construct(
        private bool $hasPrev,
        private bool $hasNext,
        private int $page,
        private int $pages
    ) {
    }

    public function toArray(): array
    {
        return [
            'pagination' => [
                'has_prev' => $this->hasPrev,
                'has_next' => $this->hasNext,
                'page' => $this->page,
                'pages' => $this->pages,
            ],
        ];
    }
}
