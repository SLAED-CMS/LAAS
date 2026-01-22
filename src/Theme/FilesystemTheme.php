<?php

declare(strict_types=1);

namespace Laas\Theme;

final class FilesystemTheme implements ThemeInterface
{
    private string $root;

    public function __construct(private string $name, string $themesRoot)
    {
        $this->root = rtrim($themesRoot, '/\\') . '/' . $name;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function viewPaths(): array
    {
        $paths = [];
        $views = $this->root . '/views';
        if (is_dir($views)) {
            $paths[] = $views;
        }
        if (is_dir($this->root)) {
            $paths[] = $this->root;
        }
        return $paths;
    }

    public function assets(): array
    {
        $manifest = $this->root . '/theme.json';
        if (!is_file($manifest)) {
            return [];
        }

        $raw = file_get_contents($manifest);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        $assets = $data['assets'] ?? null;
        return is_array($assets) ? $assets : [];
    }
}
