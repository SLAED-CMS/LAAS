<?php

declare(strict_types=1);

namespace Laas\View;

final class AssetManager
{
    private string $baseUrl;
    private string $version;
    private bool $cacheBusting;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? '/assets'), '/');
        $this->version = (string) ($config['version'] ?? '');
        $this->cacheBusting = (bool) ($config['cache_busting'] ?? true);
    }

    public function buildCss(string $name): string
    {
        $asset = $this->resolve('css', $name);
        if ($asset === null) {
            return '';
        }

        $href = $this->buildUrl($asset);
        $attrs = array_merge([
            'rel' => 'stylesheet',
            'href' => $href,
        ], $asset['attrs']);

        return '<link ' . $this->renderAttributes($attrs) . '>';
    }

    public function buildJs(string $name): string
    {
        $asset = $this->resolve('js', $name);
        if ($asset === null) {
            return '';
        }

        $src = $this->buildUrl($asset);
        $attrs = [
            'src' => $src,
        ];
        if ($asset['async']) {
            $attrs['async'] = true;
        }
        if ($asset['defer']) {
            $attrs['defer'] = true;
        }

        $attrs = array_merge($attrs, $asset['attrs']);

        return '<script ' . $this->renderAttributes($attrs) . '></script>';
    }

    private function resolve(string $group, string $name): ?array
    {
        $groupConfig = $this->config[$group] ?? [];
        if (!isset($groupConfig[$name])) {
            return null;
        }

        $entry = $groupConfig[$name];
        if (is_string($entry)) {
            $entry = ['path' => $entry];
        }
        if (!is_array($entry)) {
            return null;
        }

        $path = (string) ($entry['path'] ?? '');
        if ($path === '') {
            return null;
        }

        return [
            'path' => $path,
            'version' => (string) ($entry['version'] ?? ''),
            'async' => (bool) ($entry['async'] ?? false),
            'defer' => (bool) ($entry['defer'] ?? false),
            'attrs' => is_array($entry['attrs'] ?? null) ? $entry['attrs'] : [],
        ];
    }

    private function buildUrl(array $asset): string
    {
        $path = ltrim($asset['path'], '/');
        $url = $this->baseUrl . '/' . $path;

        $version = $asset['version'] !== '' ? $asset['version'] : $this->version;
        if ($this->cacheBusting && $version !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
        }

        return $url;
    }

    private function renderAttributes(array $attrs): string
    {
        $out = [];
        foreach ($attrs as $key => $value) {
            $name = htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($value === true) {
                $out[] = $name;
                continue;
            }
            if ($value === false || $value === null) {
                continue;
            }
            $out[] = $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return implode(' ', $out);
    }
}
