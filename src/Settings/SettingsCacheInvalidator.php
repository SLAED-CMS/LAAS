<?php
declare(strict_types=1);

namespace Laas\Settings;

use Laas\Support\Cache\CacheInterface;
use Laas\Support\Cache\CacheKey;

final class SettingsCacheInvalidator
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function invalidateKey(string $key): void
    {
        if ($key === '') {
            return;
        }

        $this->cache->delete(CacheKey::settingsKey($key));
        $this->cache->delete(CacheKey::settingsAll());
    }
}
