<?php

declare(strict_types=1);

namespace Laas\View;

interface ViewModelInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
