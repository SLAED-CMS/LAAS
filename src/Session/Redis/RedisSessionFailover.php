<?php

declare(strict_types=1);

namespace Laas\Session\Redis;

use Laas\Support\Cache\CacheFactory;
use Laas\Support\Cache\CacheInterface;

final class RedisSessionFailover
{
    private const CACHE_KEY = 'session.redis.failover';
    private CacheInterface $cache;
    private int $ttlSeconds;

    public function __construct(string $rootPath, int $ttlSeconds = 300)
    {
        $this->cache = CacheFactory::create($rootPath);
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : 300;
    }

    public function markFailure(): void
    {
        $this->cache->set(self::CACHE_KEY, ['at' => time()], $this->ttlSeconds);
    }

    public function clearFailure(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    public function hasRecentFailure(): bool
    {
        return $this->cache->get(self::CACHE_KEY) !== null;
    }
}
