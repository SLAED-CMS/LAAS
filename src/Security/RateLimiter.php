<?php

declare(strict_types=1);

namespace Laas\Security;

use Laas\Support\Cache\CacheFactory;
use RuntimeException;

final class RateLimiter
{
    private RateLimiterStoreInterface $store;
    private ClockInterface $clock;
    private string $lockDir;

    public function __construct(
        string $rootPath,
        ?RateLimiterStoreInterface $store = null,
        ?ClockInterface $clock = null
    ) {
        $this->store = $store ?? new CacheRateLimiterStore(CacheFactory::create($rootPath));
        $this->clock = $clock ?? new SystemClock();
        $this->lockDir = rtrim($rootPath, '/\\') . '/storage/cache/ratelimit';
    }

    /** @return array{allowed: bool, remaining: int, reset: int, retry_after: int} */
    public function hit(string $group, string $ip, int $windowSeconds, int $maxRequests, ?int $burst = null): array
    {
        $key = $group . ':' . $ip;
        $now = $this->clock->now();

        if ($maxRequests <= 0) {
            $window = max(1, $windowSeconds);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $now + $window,
                'retry_after' => $window,
            ];
        }

        if ($burst !== null && $burst <= 0) {
            $burst = null;
        }

        return $this->withLock($key, function () use ($key, $now, $windowSeconds, $maxRequests, $burst): array {
            $data = [
                'window_start' => $now,
                'count' => 0,
                'tokens' => $burst ?? 0,
                'updated_at' => $now,
            ];

            $stored = $this->store->get($key);
            if (is_array($stored)) {
                $data['window_start'] = isset($stored['window_start']) ? (int) $stored['window_start'] : $data['window_start'];
                $data['count'] = isset($stored['count']) ? (int) $stored['count'] : $data['count'];
                $data['tokens'] = isset($stored['tokens']) ? (float) $stored['tokens'] : $data['tokens'];
                $data['updated_at'] = isset($stored['updated_at']) ? (int) $stored['updated_at'] : $data['updated_at'];
            }

            if ($burst !== null) {
                $ratePerSecond = $maxRequests / max(1, $windowSeconds);
                $elapsed = max(0, $now - (int) $data['updated_at']);
                $tokens = min($burst, (float) $data['tokens'] + ($elapsed * $ratePerSecond));

                $allowed = $tokens >= 1.0;
                if ($allowed) {
                    $tokens -= 1.0;
                }

                $data['tokens'] = $tokens;
                $data['updated_at'] = $now;

                $remaining = (int) floor($tokens);
                $retryAfter = $allowed ? 0 : (int) ceil((1.0 - $tokens) / $ratePerSecond);
                $reset = (int) ($now + ceil(($burst - $tokens) / $ratePerSecond));
                $ttl = $this->ttlForReset($now, $reset);
                $this->store->set($key, $data, $ttl);

                return [
                    'allowed' => $allowed,
                    'remaining' => $remaining,
                    'reset' => $reset,
                    'retry_after' => $retryAfter,
                ];
            }

            if ($now >= $data['window_start'] + $windowSeconds) {
                $data['window_start'] = $now;
                $data['count'] = 0;
            }

            $allowed = $data['count'] < $maxRequests;
            if ($allowed) {
                $data['count']++;
                $reset = $data['window_start'] + $windowSeconds;
                $ttl = $this->ttlForReset($now, $reset);
                $this->store->set($key, $data, $ttl);
            }

            $reset = $data['window_start'] + $windowSeconds;
            $remaining = max(0, $maxRequests - $data['count']);
            $retryAfter = max(0, $reset - $now);

            return [
                'allowed' => $allowed,
                'remaining' => $remaining,
                'reset' => $reset,
                'retry_after' => $retryAfter,
            ];
        });
    }

    private function ttlForReset(int $now, int $reset): int
    {
        $ttl = $reset - $now;
        return $ttl > 0 ? $ttl : 1;
    }

    /** @return array{allowed: bool, remaining: int, reset: int, retry_after: int} */
    private function withLock(string $key, callable $callback): array
    {
        if (!is_dir($this->lockDir) && !mkdir($this->lockDir, 0775, true) && !is_dir($this->lockDir)) {
            throw new RuntimeException('Unable to create ratelimit directory: ' . $this->lockDir);
        }

        $lockFile = $this->lockDir . '/' . md5($key) . '.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open ratelimit lock: ' . $lockFile);
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Unable to lock ratelimit file: ' . $lockFile);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
