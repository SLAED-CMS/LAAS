<?php

declare(strict_types=1);

namespace Laas\Content\Blocks;

final class ThemeContext
{
    public function __construct(
        private string $theme,
        private string $locale
    ) {
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
