<?php
declare(strict_types=1);

namespace Laas\Modules\Menu\Service;

use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;
use Laas\Support\Cache\CacheKey;

final class MenuCacheInvalidator
{
    private CacheInterface $cache;
    /** @var array<int, string> */
    private array $locales;

    public function __construct(?CacheInterface $cache = null, ?array $locales = null)
    {
        $rootPath = dirname(__DIR__, 3);
        $this->cache = $cache ?? CacheFactory::create($rootPath);
        $this->locales = $locales ?? $this->loadLocales($rootPath);
    }

    public function invalidate(string $name): void
    {
        if ($name === '') {
            return;
        }

        foreach ($this->locales as $locale) {
            $this->cache->delete(CacheKey::menu($name, $locale));
        }
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
