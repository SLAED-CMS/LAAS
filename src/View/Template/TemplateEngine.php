<?php

declare(strict_types=1);

namespace Laas\View\Template;

use Laas\Support\Audit;
use Laas\Support\RequestScope;
use Laas\Theme\TemplateResolver;
use Laas\Theme\ThemeInterface;
use Laas\View\SanitizedHtml;
use Laas\View\Theme\ThemeManager;
use RuntimeException;

final class TemplateEngine
{
    private array $blocks = [];
    private string $rawMode;

    public function __construct(
        private ThemeManager $themeManager,
        private TemplateCompiler $compiler,
        private string $cachePath,
        private bool $debug,
        string $rawMode = 'escape',
        private ?TemplateResolver $templateResolver = null,
        private ?ThemeInterface $theme = null
    ) {
        $this->rawMode = $this->normalizeRawMode($rawMode);
    }

    public function render(string $template, array $data, array $options = []): string
    {
        $options = array_merge([
            'render_partial' => false,
            'collect_blocks' => false,
        ], $options);

        $templatePath = $this->resolveTemplatePath($template);
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
        $templatePath = $this->resolveTemplatePath($template);
        $cacheFile = $this->compile($templatePath);
        $options['template'] = $template;
        $options['template_path'] = $templatePath;

        $hasTemplate = RequestScope::has('template.current');
        $previousTemplate = $hasTemplate ? RequestScope::get('template.current') : null;
        RequestScope::set('template.current', $template);

        ob_start();
        try {
            include $cacheFile;
        } finally {
            if ($hasTemplate) {
                RequestScope::set('template.current', $previousTemplate);
            } else {
                RequestScope::forget('template.current');
            }
        }
        $output = (string) ob_get_clean();

        return $output;
    }

    public function compileTemplate(string $template): string
    {
        $templatePath = $this->resolveTemplatePath($template);
        return $this->compile($templatePath);
    }

    public function compilePath(string $templatePath): string
    {
        return $this->compile($templatePath);
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

    public function raw(mixed $value, string $expression = '', array $ctx = [], array $options = []): string
    {
        $template = $this->resolveTemplateName($options);

        if ($value instanceof SanitizedHtml) {
            $this->auditRaw('template.raw_used', $template, $expression, true);
            return $value->toString();
        }

        if ($this->rawMode === 'allow') {
            $this->auditRaw('template.raw_used', $template, $expression, false);
            return (string) ($value ?? '');
        }

        $this->auditRaw('template.raw_blocked', $template, $expression, false);

        if ($this->rawMode === 'strict' && $this->debug) {
            $label = $expression !== '' ? $expression : 'unknown';
            $templateLabel = $template !== '' ? ' in ' . $template : '';
            throw new RuntimeException('Raw output requires SanitizedHtml: ' . $label . $templateLabel);
        }

        return $this->escape($value);
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
            'asset_css' => $this->buildAssetTag('css', $arg, $ctx),
            'asset_js' => $this->buildAssetTag('js', $arg, $ctx),
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

    private function buildAssetTag(string $type, mixed $arg, array $ctx): string
    {
        $name = is_string($arg) ? $arg : '';
        if ($name === '') {
            return '';
        }

        $manager = $ctx['__assets'] ?? null;
        if (!is_object($manager)) {
            return '';
        }

        if ($type === 'css' && method_exists($manager, 'buildCss')) {
            return (string) $manager->buildCss($name);
        }
        if ($type === 'js' && method_exists($manager, 'buildJs')) {
            return (string) $manager->buildJs($name);
        }

        return '';
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
        if (!$needsCompile && !$this->debug) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && str_contains($cached, '{%')) {
                $needsCompile = true;
            }
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

    private function normalizeRawMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['strict', 'escape', 'allow'], true)) {
            return 'escape';
        }
        return $mode;
    }

    private function resolveTemplatePath(string $template): string
    {
        if ($this->templateResolver !== null && $this->theme instanceof ThemeInterface) {
            return $this->templateResolver->resolve($template, $this->theme);
        }

        return $this->themeManager->resolvePath($template);
    }

    private function resolveTemplateName(array $options): string
    {
        $value = $options['template'] ?? '';
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $current = RequestScope::get('template.current');
        return is_string($current) ? $current : '';
    }

    private function auditRaw(string $action, string $template, string $expression, bool $sanitized): void
    {
        $key = $action === 'template.raw_used' ? 'template.raw.used_logged' : 'template.raw.blocked_logged';
        if (RequestScope::has($key)) {
            return;
        }
        $hook = RequestScope::get('template.raw_audit');
        $request = RequestScope::getRequest();
        if ($request === null && !is_callable($hook)) {
            return;
        }

        RequestScope::set($key, true);

        $context = [
            'template' => $template,
            'expression' => $expression,
            'raw_mode' => $this->rawMode,
            'sanitized' => $sanitized,
        ];
        if ($request !== null) {
            $context['route'] = $request->getPath();
        }
        if (is_callable($hook)) {
            $hook($action, $context);
            return;
        }

        Audit::log($action, 'template', null, $context);
    }
}
