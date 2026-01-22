<?php

declare(strict_types=1);

namespace Laas\View\Template;

use Laas\View\Theme\ThemeManager;

final class TemplateWarmupService
{
    private TemplateCompiler $compiler;

    public function __construct(private TemplateEngine $engine, ?TemplateCompiler $compiler = null)
    {
        $this->compiler = $compiler ?? new TemplateCompiler();
    }

    /** @return array{compiled: int, errors: array<int, string>} */
    public function warmupTheme(ThemeManager $themeManager): array
    {
        $compiled = 0;
        $errors = [];
        $templates = $this->discoverTemplates($themeManager->basePath());
        $seen = [];

        foreach ($templates as $path) {
            foreach ($this->collectDependencies($themeManager, $path, $seen) as $dep) {
                try {
                    $this->engine->compilePath($dep);
                    $compiled++;
                } catch (\Throwable $e) {
                    $errors[] = $dep . ': ' . $e->getMessage();
                }
            }
        }

        return [
            'compiled' => $compiled,
            'errors' => $errors,
        ];
    }

    /** @return array<int, string> */
    private function discoverTemplates(string $basePath): array
    {
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.html')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    /** @return array<int, string> */
    private function collectDependencies(ThemeManager $themeManager, string $path, array &$seen): array
    {
        if (isset($seen[$path])) {
            return [];
        }
        $seen[$path] = true;

        $deps = [$path];
        $source = (string) file_get_contents($path);

        $extends = $this->compiler->extractExtends($source);
        if ($extends !== null) {
            $depPath = $themeManager->resolvePath($extends);
            $deps = array_merge($deps, $this->collectDependencies($themeManager, $depPath, $seen));
        }

        preg_match_all('/\{\%\s*include\s+[\'"]([^\'"]+)[\'"]\s*\%\}/', $source, $matches);
        foreach ($matches[1] as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $depPath = $themeManager->resolvePath($name);
            $deps = array_merge($deps, $this->collectDependencies($themeManager, $depPath, $seen));
        }

        return $deps;
    }
}
