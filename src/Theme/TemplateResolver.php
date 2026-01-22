<?php

declare(strict_types=1);

namespace Laas\Theme;

use InvalidArgumentException;

final class TemplateResolver
{
    /** @var callable|null */
    private $fallback;

    public function __construct(?callable $fallback = null)
    {
        $this->fallback = $fallback;
    }

    public function withFallback(callable $fallback): self
    {
        $clone = clone $this;
        $clone->fallback = $fallback;
        return $clone;
    }

    public function resolve(string $template, ThemeInterface $theme): string
    {
        if ($this->isAbsolutePath($template)) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template);
            clearstatcache(true, $normalized);
            if (!is_file($normalized) && !file_exists($normalized)) {
                $handle = @fopen($normalized, 'rb');
                if ($handle === false) {
                    throw new InvalidArgumentException('Template not found: ' . $template);
                }
                fclose($handle);
            }
            return $template;
        }

        if (str_contains($template, '..')) {
            throw new InvalidArgumentException('Invalid template path: ' . $template);
        }

        foreach ($theme->viewPaths() as $dir) {
            if ($dir === '') {
                continue;
            }
            $path = rtrim($dir, '/\\') . '/' . ltrim($template, '/\\');
            if (is_file($path)) {
                return $path;
            }
        }

        if (is_callable($this->fallback)) {
            return (string) ($this->fallback)($template, $theme);
        }

        throw new InvalidArgumentException('Template not found: ' . $template);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        if (strlen($path) < 3) {
            return false;
        }
        if (!ctype_alpha($path[0]) || $path[1] !== ':') {
            return false;
        }
        return $path[2] === '\\' || $path[2] === '/';
    }
}
