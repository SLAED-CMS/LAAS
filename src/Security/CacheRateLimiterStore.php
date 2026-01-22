<?php

declare(strict_types=1);

namespace Laas\Security;

use Laas\Support\Cache\CacheInterface;

final class CacheRateLimiterStore implements RateLimiterStoreInterface
{
    private string $prefix;

    public function __construct(private CacheInterface $cache, string $prefix = 'ratelimit')
    {
        $this->prefix = $prefix !== '' ? $prefix : 'ratelimit';
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->prefix . ':' . $key, null);
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $state, int $ttlSeconds): bool
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : 0;
        return $this->cache->set($this->prefix . ':' . $key, $state, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($this->prefix . ':' . $key);
    }
}
