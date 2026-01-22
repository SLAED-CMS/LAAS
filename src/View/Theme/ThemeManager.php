<?php

declare(strict_types=1);

namespace Laas\View\Theme;

use Laas\DevTools\DevToolsContext;
use Laas\Settings\SettingsProvider;
use Laas\Support\RequestScope;
use Laas\Theme\ThemeCapabilities;
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

    /**
     * @return array<int, string>
     */
    public function getCapabilities(?string $theme = null): array
    {
        $config = $this->getThemeConfig($theme);
        $caps = $config['capabilities'] ?? [];
        if (!is_array($caps)) {
            return [];
        }
        return ThemeCapabilities::normalize($caps);
    }

    /**
     * @return array<int, string>
     */
    public function getProvides(?string $theme = null): array
    {
        $config = $this->getThemeConfig($theme);
        $provides = $config['provides'] ?? [];
        if (!is_array($provides)) {
            return [];
        }
        return array_values(array_filter($provides, 'is_string'));
    }

    public function getThemeApi(?string $theme = null): ?string
    {
        $config = $this->getThemeConfig($theme);
        return is_string($config['api'] ?? null) ? $config['api'] : null;
    }

    public function getThemeVersion(?string $theme = null): ?string
    {
        $config = $this->getThemeConfig($theme);
        return is_string($config['version'] ?? null) ? $config['version'] : null;
    }

    public function getLayoutPath(string $key = 'base', ?string $theme = null): string
    {
        $theme = $theme ?? $this->defaultTheme;
        $themePath = $this->themePath($theme);
        $config = $this->getThemeConfig($theme);

        $candidates = [];
        if ($key === 'base') {
            $candidates[] = 'layouts/base.html';
        }
        if ($config !== []) {
            $layouts = $config['layouts'] ?? [];
            $layout = $layouts[$key] ?? '';
            if (is_string($layout) && $layout !== '') {
                $candidates[] = $layout;
            }
        }
        $candidates[] = 'layout.html';
        if ($theme === $this->getAdminTheme()) {
            $candidates[] = 'admin.html';
        }
        $candidates[] = 'layouts/layout.html';

        $usedFallback = false;
        foreach ($this->uniqueCandidates($candidates) as $candidate) {
            $path = $themePath . '/' . ltrim($candidate, '/\\');
            if (is_file($path)) {
                if ($key === 'base' && $candidate !== 'layouts/base.html') {
                    $usedFallback = true;
                }
                if ($usedFallback) {
                    $this->warnDeprecatedLayout($theme, $candidate);
                }
                return $candidate;
            }
        }

        return 'layout.html';
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

        return is_file($path . '/layouts/base.html') || is_file($path . '/layout.html') || is_file($path . '/theme.json');
    }

    private function themePath(string $theme): string
    {
        return rtrim($this->themesRoot, '/\\') . '/' . $theme;
    }

    /**
     * @param array<int, string> $candidates
     * @return array<int, string>
     */
    private function uniqueCandidates(array $candidates): array
    {
        $out = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            if ($candidate === '') {
                continue;
            }
            if (isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            $out[] = $candidate;
        }
        return $out;
    }

    private function warnDeprecatedLayout(string $theme, string $layout): void
    {
        $message = 'Theme layout fallback used for ' . $theme . ': ' . $layout;

        $context = RequestScope::get('devtools.context');
        if ($context instanceof DevToolsContext && (bool) $context->getFlag('debug', false)) {
            $context->addWarning('theme_layout_deprecated', $message);
        }
    }
}
