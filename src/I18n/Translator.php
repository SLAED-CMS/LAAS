<?php
declare(strict_types=1);

namespace Laas\I18n;

use RuntimeException;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    public function __construct(
        private string $rootPath,
        private string $theme,
        private string $defaultLocale
    ) {
    }

    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->defaultLocale;
        $catalog = $this->load($locale);
        $value = $catalog[$key] ?? $key;

        return $this->replaceParams($value, $params);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        $locale = $locale ?? $this->defaultLocale;
        $catalog = $this->load($locale);

        return array_key_exists($key, $catalog);
    }

    public function transChoice(string $baseKey, int $count, array $params = [], ?string $locale = null): string
    {
        $suffix = $count === 1 ? 'one' : 'other';
        $params = array_merge($params, ['count' => $count]);

        return $this->trans($baseKey . '.' . $suffix, $params, $locale);
    }

    /** @return array<string, string> */
    private function load(string $locale): array
    {
        if (isset($this->catalogs[$locale])) {
            return $this->catalogs[$locale];
        }

        $catalog = [];

        $corePath = $this->rootPath . '/resources/lang/' . $locale;
        if (is_dir($corePath)) {
            foreach (glob($corePath . '/*.php') ?: [] as $file) {
                $catalog = array_merge($catalog, $this->loadFile($file));
            }
        }

        foreach (glob($this->rootPath . '/modules/*/lang/' . $locale . '.php') ?: [] as $file) {
            $catalog = array_merge($catalog, $this->loadFile($file));
        }

        $themeFile = $this->rootPath . '/themes/' . $this->theme . '/lang/' . $locale . '.php';
        if (is_file($themeFile)) {
            $catalog = array_merge($catalog, $this->loadFile($themeFile));
        }

        $this->catalogs[$locale] = $catalog;

        return $catalog;
    }

    /** @return array<string, string> */
    private function loadFile(string $path): array
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException('Translation file must return array: ' . $path);
        }

        return $data;
    }

    private function replaceParams(string $value, array $params): string
    {
        foreach ($params as $key => $param) {
            $value = str_replace('{' . $key . '}', (string) $param, $value);
        }

        return $value;
    }
}
