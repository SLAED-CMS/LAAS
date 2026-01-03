<?php
declare(strict_types=1);

namespace Laas\Support\Cache;

final class CacheFactory
{
    public static function create(string $rootPath): CacheInterface
    {
        $config = self::loadConfig($rootPath);
        $enabled = (bool) ($config['enabled'] ?? true);
        if (!$enabled) {
            return new NullCache();
        }

        $dir = rtrim($rootPath, '/\\') . '/storage/cache/data';
        $prefix = (string) ($config['prefix'] ?? 'laas');
        $ttl = (int) ($config['ttl_default'] ?? 300);

        return new FileCache($dir, $prefix, $ttl);
    }

    private static function loadConfig(string $rootPath): array
    {
        $path = rtrim($rootPath, '/\\') . '/config/cache.php';
        if (!is_file($path)) {
            return [
                'enabled' => true,
                'prefix' => 'laas',
                'ttl_default' => 300,
            ];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }
}
