<?php

declare(strict_types=1);

namespace Laas\Theme;

interface ThemeInterface
{
    public function name(): string;

    /** @return array<int, string> */
    public function viewPaths(): array;

    /** @return array<string, mixed> */
    public function assets(): array;
}
