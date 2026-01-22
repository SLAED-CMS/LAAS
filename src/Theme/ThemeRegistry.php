<?php

declare(strict_types=1);

namespace Laas\Theme;

final class ThemeRegistry
{
    /** @var array<string, ThemeInterface> */
    private array $themes = [];

    public function __construct(private string $themesRoot, private string $defaultName = 'default')
    {
    }

    public function register(ThemeInterface $theme): void
    {
        $this->themes[$theme->name()] = $theme;
    }

    public function get(string $name): ?ThemeInterface
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (isset($this->themes[$name])) {
            return $this->themes[$name];
        }

        $theme = $this->loadFromDisk($name);
        if ($theme !== null) {
            $this->themes[$name] = $theme;
        }

        return $theme;
    }

    public function default(): ThemeInterface
    {
        $theme = $this->get($this->defaultName);
        if ($theme instanceof ThemeInterface) {
            return $theme;
        }

        $fallback = new FilesystemTheme($this->defaultName, $this->themesRoot);
        $this->themes[$this->defaultName] = $fallback;
        return $fallback;
    }

    private function loadFromDisk(string $name): ?ThemeInterface
    {
        $themePath = rtrim($this->themesRoot, '/\\') . '/' . $name;
        if (!is_dir($themePath)) {
            return null;
        }

        $themeFile = $themePath . '/theme.php';
        if (is_file($themeFile)) {
            $theme = require $themeFile;
            if ($theme instanceof ThemeInterface) {
                return $theme;
            }
        }

        return new FilesystemTheme($name, $this->themesRoot);
    }
}
