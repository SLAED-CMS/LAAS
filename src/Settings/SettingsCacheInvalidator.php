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
            if ($this->shouldLog()) {
                error_log("[CacheInvalidator] invalidateKey() - empty key, skipping");
            }
            return;
        }

        if ($this->shouldLog()) {
            error_log("[CacheInvalidator] invalidateKey({$key}) - deleting individual key");
        }
        $result1 = $this->cache->delete(CacheKey::settingsKey($key));
        if ($this->shouldLog()) {
            error_log("[CacheInvalidator] invalidateKey({$key}) - individual key delete result: " . ($result1 ? 'OK' : 'FAIL'));
        }

        if ($this->shouldLog()) {
            error_log("[CacheInvalidator] invalidateKey({$key}) - deleting settingsAll");
        }
        $result2 = $this->cache->delete(CacheKey::settingsAll());
        if ($this->shouldLog()) {
            error_log("[CacheInvalidator] invalidateKey({$key}) - settingsAll delete result: " . ($result2 ? 'OK' : 'FAIL'));
        }
    }

    private function shouldLog(): bool
    {
        $env = strtolower((string) getenv('APP_ENV'));
        if ($env === 'test') {
            return false;
        }
        if (getenv('CI') === 'true') {
            return false;
        }
        return true;
    }
}
