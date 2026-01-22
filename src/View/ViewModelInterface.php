<?php

declare(strict_types=1);

namespace Laas\View;

interface ViewModelInterface
{
    public function toArray(): array;
}
