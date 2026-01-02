<?php
declare(strict_types=1);

namespace Laas\View\Template;

use Laas\View\Theme\ThemeManager;
use RuntimeException;

final class TemplateEngine
{
    private array $blocks = [];

    public function __construct(
        private ThemeManager $themeManager,
        private TemplateCompiler $compiler,
        private string $cachePath,
        private bool $debug
    ) {
    }

    public function render(string $template, array $data, array $options = []): string
    {
        $options = array_merge([
            'render_partial' => false,
            'collect_blocks' => false,
        ], $options);

        $templatePath = $this->themeManager->resolvePath($template);
        $source = (string) file_get_contents($templatePath);
        $parent = $this->compiler->extractExtends($source);

        $previousBlocks = $this->blocks;

        try {
            if ($parent !== null) {
                $this->blocks = [];
                $this->includeTemplate($template, $data, array_merge($options, ['collect_blocks' => true]));

                if (!empty($options['render_partial'])) {
                    if (isset($this->blocks['content'])) {
                        return $this->blocks['content'];
                    }

                    return $this->includeTemplate($template, $data, $options);
                }

                return $this->includeTemplate($parent, $data, $options);
            }

            return $this->includeTemplate($template, $data, $options);
        } finally {
            $this->blocks = $previousBlocks;
        }
    }

    public function includeTemplate(string $template, array $ctx, array $options): string
    {
        $templatePath = $this->themeManager->resolvePath($template);
        $cacheFile = $this->compile($templatePath);

        ob_start();
        include $cacheFile;
        $output = (string) ob_get_clean();

        return $output;
    }

    public function block(string $name, callable $default, array $options): void
    {
        if (!empty($options['collect_blocks'])) {
            $this->blocks[$name] = $this->capture($default);
            return;
        }

        if (isset($this->blocks[$name])) {
            echo $this->blocks[$name];
            return;
        }

        echo $this->capture($default);
    }

    public function value(array $ctx, string $path): mixed
    {
        $current = $ctx;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            if (is_object($current) && isset($current->{$segment})) {
                $current = $current->{$segment};
                continue;
            }

            return null;
        }

        return $current;
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function raw(mixed $value): string
    {
        return (string) ($value ?? '');
    }

    public function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }

        return !empty($value);
    }

    public function helper(string $name, mixed $arg, array $ctx): string
    {
        return match ($name) {
            'csrf' => (string) ($ctx['csrf_token'] ?? ''),
            'url' => is_string($arg) && str_starts_with($arg, '/') ? $arg : (string) ($arg ?? ''),
            'asset' => '/assets/' . ltrim((string) ($arg ?? ''), '/'),
            't' => $this->translate($arg, $ctx),
            'menu' => $this->renderMenu($arg, $ctx),
            'blocks' => '',
            default => '',
        };
    }

    private function translate(mixed $arg, array $ctx): string
    {
        if (!is_array($arg)) {
            return (string) ($arg ?? '');
        }

        $key = (string) ($arg['key'] ?? '');
        $params = is_array($arg['params'] ?? null) ? $arg['params'] : [];
        $translator = $ctx['__translator'] ?? null;
        $locale = $ctx['locale'] ?? null;

        if (is_object($translator) && method_exists($translator, 'trans')) {
            return (string) $translator->trans($key, $params, is_string($locale) ? $locale : null);
        }

        return $key;
    }

    private function renderMenu(mixed $arg, array $ctx): string
    {
        $name = is_string($arg) ? $arg : '';
        if ($name === '') {
            return '';
        }

        $resolver = $ctx['__menu'] ?? null;
        if (!is_callable($resolver)) {
            return '';
        }

        return (string) $resolver($name);
    }

    private function compile(string $templatePath): string
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException('Template not found: ' . $templatePath);
        }

        if (!is_dir($this->cachePath) && !mkdir($this->cachePath, 0775, true) && !is_dir($this->cachePath)) {
            throw new RuntimeException('Unable to create template cache directory: ' . $this->cachePath);
        }

        $cacheFile = rtrim($this->cachePath, '/\\') . '/' . sha1($templatePath) . '.php';

        $needsCompile = !is_file($cacheFile);
        if (!$needsCompile && $this->debug) {
            $needsCompile = filemtime($templatePath) > filemtime($cacheFile);
        }

        if ($needsCompile) {
            $source = (string) file_get_contents($templatePath);
            $compiled = $this->compiler->compile($source);
            $php = "<?php\n" . "declare(strict_types=1);\n" . "?>\n" . $compiled;
            file_put_contents($cacheFile, $php);
        }

        return $cacheFile;
    }

    private function capture(callable $callback): string
    {
        ob_start();
        $callback();
        return (string) ob_get_clean();
    }
}
