<?php
declare(strict_types=1);

namespace Laas\View\Theme;

use Laas\Settings\SettingsProvider;
use RuntimeException;

final class ThemeManager
{
    public function __construct(
        private string $themesRoot,
        private string $defaultTheme,
        private ?SettingsProvider $settingsProvider = null
    ) {
    }

    public function getThemesRoot(): string
    {
        return $this->themesRoot;
    }

    public function getThemeName(): string
    {
        return $this->defaultTheme;
    }

    public function getPublicTheme(): string
    {
        $theme = $this->defaultTheme;
        if ($this->settingsProvider !== null) {
            $theme = (string) $this->settingsProvider->get('theme', $theme);
        }

        if ($theme === $this->getAdminTheme()) {
            return $this->defaultTheme;
        }

        if ($this->isThemeAvailable($theme)) {
            return $theme;
        }

        return $this->defaultTheme;
    }

    public function getAdminTheme(): string
    {
        return 'admin';
    }

    public function basePath(): string
    {
        return rtrim($this->themesRoot, '/\\') . '/' . $this->defaultTheme;
    }

    public function resolvePath(string $template): string
    {
        $base = $this->basePath();
        $path = rtrim($base, '/\\') . '/' . ltrim($template, '/\\');

        if (!is_file($path)) {
            throw new RuntimeException('Template not found: ' . $path);
        }

        return $path;
    }

    private function isThemeAvailable(string $theme): bool
    {
        if ($theme === '') {
            return false;
        }

        $path = rtrim($this->themesRoot, '/\\') . '/' . $theme;
        if (!is_dir($path)) {
            return false;
        }

        return is_file($path . '/layout.html') || is_file($path . '/theme.json');
    }
}
