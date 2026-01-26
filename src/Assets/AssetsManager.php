<?php

declare(strict_types=1);

namespace Laas\Assets;

final class AssetsManager
{
    public function __construct(private array $config)
    {
    }

    public function all(): array
    {
        $base = $this->envString('ASSET_BASE', (string) ($this->config['asset_base'] ?? $this->config['base_url'] ?? '/assets'));
        $base = $this->normalizeBase($base);

        $vendorBase = $this->envString('ASSET_VENDOR_BASE', (string) ($this->config['asset_vendor_base'] ?? ($base . '/vendor')));
        $vendorBase = $this->normalizeBase($vendorBase);

        $appBase = $this->envString('ASSET_APP_BASE', (string) ($this->config['asset_app_base'] ?? ($base . '/app')));
        $appBase = $this->normalizeBase($appBase);

        $versions = is_array($this->config['versions'] ?? null) ? $this->config['versions'] : [];
        $bootstrapVersion = $this->envString('ASSET_BOOTSTRAP_VERSION', (string) ($versions['bootstrap'] ?? '5.3.3'));
        $htmxVersion = $this->envString('ASSET_HTMX_VERSION', (string) ($versions['htmx'] ?? '1.9.12'));
        $bootstrapIconsVersion = $this->envString('ASSET_BOOTSTRAP_ICONS_VERSION', (string) ($versions['bootstrap_icons'] ?? '1.11.3'));

        return [
            'bootstrap_css' => $this->resolveAsset('bootstrap_css', $this->join($vendorBase, 'bootstrap/' . $bootstrapVersion . '/bootstrap.min.css')),
            'bootstrap_js' => $this->resolveAsset('bootstrap_js', $this->join($vendorBase, 'bootstrap/' . $bootstrapVersion . '/bootstrap.bundle.min.js')),
            'bootstrap_icons_css' => $this->resolveAsset('bootstrap_icons_css', $this->join($vendorBase, 'bootstrap-icons/' . $bootstrapIconsVersion . '/bootstrap-icons.min.css')),
            'htmx_js' => $this->resolveAsset('htmx_js', $this->join($vendorBase, 'htmx/' . $htmxVersion . '/htmx.min.js')),
            'tinymce_js' => $this->resolveAsset('tinymce_js', $this->join($vendorBase, 'tinymce/tinymce.min.js')),
            'toastui_editor_css' => $this->resolveAsset('toastui_editor_css', $this->join($vendorBase, 'toastui-editor/toastui-editor.min.css')),
            'toastui_editor_js' => $this->resolveAsset('toastui_editor_js', $this->join($vendorBase, 'toastui-editor/toastui-editor.min.js')),
            'app_css' => $this->resolveAsset('app_css', $this->join($appBase, 'app.css')),
            'app_js' => $this->resolveAsset('app_js', $this->join($appBase, 'app.js')),
            'devtools_css' => $this->resolveAsset('devtools_css', $this->join($appBase, 'devtools.css')),
            'devtools_js' => $this->resolveAsset('devtools_js', $this->join($appBase, 'devtools.js')),
            'admin_css' => $this->resolveAsset('admin_css', $this->join($base, 'admin.css')),
            'admin_js' => $this->resolveAsset('admin_js', $this->join($base, 'admin.js')),
            'pages_admin_editors_js' => $this->resolveAsset('pages_admin_editors_js', $this->join($base, 'admin-pages-editors.js')),
        ];
    }

    public function hasTinyMce(): bool
    {
        return $this->assetFileExists('vendor/tinymce/tinymce.min.js');
    }

    public function hasToastUi(): bool
    {
        return $this->assetFileExists('vendor/toastui-editor/toastui-editor.min.js');
    }

    /**
     * @return array<string, string>
     */
    public function editorAssets(): array
    {
        $assets = $this->all();
        return [
            'tinymce_js' => (string) ($assets['tinymce_js'] ?? ''),
            'toastui_editor_css' => (string) ($assets['toastui_editor_css'] ?? ''),
            'toastui_editor_js' => (string) ($assets['toastui_editor_js'] ?? ''),
            'pages_admin_editors_js' => (string) ($assets['pages_admin_editors_js'] ?? ''),
        ];
    }

    private function assetFileExists(string $assetPath): bool
    {
        $base = $this->envString('ASSET_BASE', (string) ($this->config['asset_base'] ?? $this->config['base_url'] ?? '/assets'));
        $base = $this->normalizeBase($base);
        if (str_contains($base, '://')) {
            return false;
        }
        $base = '/' . ltrim($base, '/');
        $root = dirname(__DIR__, 3);
        $fullPath = $root . '/public' . $base . '/' . ltrim($assetPath, '/');
        return is_file($fullPath);
    }

    private function envString(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    private function normalizeBase(string $value): string
    {
        $value = $this->normalizePath($value);
        return rtrim($value, '/');
    }

    private function normalizePath(string $value): string
    {
        $value = str_replace('\\', '/', $value);
        if (str_contains($value, '://')) {
            [$scheme, $rest] = explode('://', $value, 2);
            $rest = preg_replace('#/+#', '/', $rest) ?? $rest;
            return $scheme . '://' . $rest;
        }
        return preg_replace('#/+#', '/', $value) ?? $value;
    }

    private function join(string $base, string $path): string
    {
        $base = $this->normalizeBase($base);
        $path = ltrim($this->normalizePath($path), '/');
        if ($base === '') {
            return '/' . $path;
        }
        return $base . '/' . $path;
    }

    private function resolveAsset(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;
        if ($value === null) {
            return '';
        }
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return $this->normalizePath($value);
    }
}
