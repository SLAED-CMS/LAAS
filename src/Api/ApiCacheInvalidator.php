<?php

declare(strict_types=1);

namespace Laas\Api;

use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;

final class ApiCacheInvalidator
{
    private CacheInterface $cache;
    /** @var array<int, string> */
    private array $locales;

    public function __construct(?CacheInterface $cache = null, ?array $locales = null, ?string $rootPath = null)
    {
        $root = $rootPath ?? dirname(__DIR__, 2);
        $this->cache = $cache ?? CacheFactory::create($root);
        $this->locales = $locales ?? $this->loadLocales($root);
    }

    public function bumpPages(): void
    {
        $this->bump(ApiCache::pagesVersionKey());
    }

    public function bumpMedia(): void
    {
        $this->bump(ApiCache::mediaVersionKey());
    }

    public function invalidateMenu(string $name): void
    {
        if ($name === '') {
            return;
        }

        foreach ($this->locales as $locale) {
            $this->cache->delete((new ApiCache($this->cache))->menuKey($name, $locale));
        }
    }

    private function bump(string $key): void
    {
        $current = $this->cache->get($key);
        $value = 1;

        if (is_int($current) && $current > 0) {
            $value = $current + 1;
        } elseif (is_string($current) && ctype_digit($current)) {
            $value = (int) $current + 1;
        } else {
            $value = 2;
        }

        $this->cache->set($key, $value);
    }

    /** @return array<int, string> */
    private function loadLocales(string $rootPath): array
    {
        $path = $rootPath . '/config/app.php';
        if (!is_file($path)) {
            return ['en'];
        }

        $config = require $path;
        if (!is_array($config)) {
            return ['en'];
        }

        $locales = $config['locales'] ?? [];
        if (!is_array($locales) || $locales === []) {
            return ['en'];
        }

        $out = [];
        foreach ($locales as $locale) {
            if (is_string($locale) && $locale !== '') {
                $out[] = $locale;
            }
        }

        return $out !== [] ? $out : ['en'];
    }
}
