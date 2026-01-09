<?php
declare(strict_types=1);

namespace Laas\View\Theme;

use Laas\Settings\SettingsProvider;
use RuntimeException;

final class ThemeManager
{
    private array $themeCache = [];

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

    public function getThemeConfig(?string $theme = null): array
    {
        $theme = $theme ?? $this->defaultTheme;
        if (isset($this->themeCache[$theme])) {
            return $this->themeCache[$theme];
        }

        $path = $this->themePath($theme);
        $manifestPath = $path . '/theme.json';
        if (!is_file($manifestPath)) {
            $this->themeCache[$theme] = [];
            return [];
        }

        $raw = (string) file_get_contents($manifestPath);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid theme.json: ' . $manifestPath);
        }

        $layouts = $data['layouts'] ?? null;
        if (!is_array($layouts)) {
            throw new RuntimeException('Missing layouts in theme.json: ' . $manifestPath);
        }

        $baseLayout = (string) ($layouts['base'] ?? '');
        if ($baseLayout === '') {
            throw new RuntimeException('Missing base layout in theme.json: ' . $manifestPath);
        }

        $basePath = $path . '/' . ltrim($baseLayout, '/\\');
        if (!is_file($basePath)) {
            throw new RuntimeException('Base layout not found: ' . $basePath);
        }

        $this->themeCache[$theme] = $data;
        return $data;
    }

    public function getLayoutPath(string $key = 'base', ?string $theme = null): string
    {
        $config = $this->getThemeConfig($theme);
        if ($config === []) {
            return 'layout.html';
        }

        $layouts = $config['layouts'] ?? [];
        $layout = $layouts[$key] ?? '';
        if (!is_string($layout) || $layout === '') {
            return 'layout.html';
        }

        return $layout;
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

    private function themePath(string $theme): string
    {
        return rtrim($this->themesRoot, '/\\') . '/' . $theme;
    }
}
