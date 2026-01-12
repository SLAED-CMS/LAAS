<?php
declare(strict_types=1);

namespace Laas\Theme;

final class ThemeValidator
{
    /** @var array<int, string> */
    private array $cdnPatterns = [
        'https://cdn.',
        'https://cdn.jsdelivr.net',
        'https://unpkg.com',
        'https://cdnjs.cloudflare.com',
        'https://fonts.googleapis.com',
        'https://googleapis.com',
    ];

    /** @var array<int, string> */
    private array $requiredPartials = [
        'partials/header.html',
    ];

    public function __construct(private string $themesRoot)
    {
    }

    public function validateTheme(string $themeName): ThemeValidationResult
    {
        $result = new ThemeValidationResult($themeName);
        $themePath = rtrim($this->themesRoot, '/\\') . '/' . $themeName;

        $baseLayout = $themePath . '/layouts/base.html';
        if (!is_file($baseLayout)) {
            $result->addViolation('layout_missing', $baseLayout, 'Missing layouts/base.html');
        }

        foreach ($this->requiredPartials as $partial) {
            $path = $themePath . '/' . ltrim($partial, '/\\');
            if (!is_file($path)) {
                $result->addViolation('partial_missing', $path, 'Missing required partial: ' . $partial);
            }
        }

        foreach ($this->listHtmlFiles($themePath) as $file) {
            $contents = @file_get_contents($file);
            if (!is_string($contents)) {
                continue;
            }

            if ($this->hasInlineStyle($contents)) {
                $result->addViolation('inline_style', $file, 'Inline <style> is forbidden');
            }

            if ($this->hasInlineScript($contents)) {
                $result->addViolation('inline_script', $file, 'Inline <script> is forbidden');
            }

            foreach ($this->cdnPatterns as $pattern) {
                if (stripos($contents, $pattern) !== false) {
                    $result->addViolation('cdn_usage', $file, 'CDN usage is forbidden');
                    break;
                }
            }
        }

        return $result;
    }

    private function hasInlineStyle(string $contents): bool
    {
        return preg_match('/<style\\b/i', $contents) === 1;
    }

    private function hasInlineScript(string $contents): bool
    {
        if (preg_match_all('/<script\\b[^>]*>/i', $contents, $matches) <= 0) {
            return false;
        }
        foreach ($matches[0] as $tag) {
            if (preg_match('/\\bsrc\\s*=\\s*/i', $tag) === 1) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function listHtmlFiles(string $path): array
    {
        $files = [];
        if (!is_dir($path)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'html') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
